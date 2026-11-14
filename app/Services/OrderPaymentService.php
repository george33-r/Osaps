<?php

namespace App\Services;

use App\Domain\Payments\ChargeCheck;
use App\Domain\Orders\OrderStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes the customer's payment for a tailor order. The order becomes paid only when Paystack's
 * own record (verify call or signed webhook) confirms it for this reference and amount.
 */
class OrderPaymentService
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly Notifier $notifier) {}

    public function start(User $customer, Order $order, string $callbackUrl): OrderPayment
    {
        if ((int) $order->customer_id !== (int) $customer->id) {
            throw new AuthorizationException;
        }
        $order = Order::query()->findOrFail($order->id);
        if ($order->status !== OrderStatus::CONFIRMED) {
            throw new DomainRuleViolation('You can pay once the tailor has confirmed the order.', 'not_payable');
        }
        if ($order->payment_status === OrderPayment::PAID) {
            throw new DomainRuleViolation('This order is already paid.', 'already_paid');
        }

        $payment = DB::transaction(function () use ($order) {
            $existing = OrderPayment::query()->where('order_id', $order->id)->where('status', OrderPayment::PENDING)->lockForUpdate()->first();

            return $existing ?? OrderPayment::query()->create([
                'order_id' => $order->id,
                'reference' => self::newReference(),
                'amount_minor' => $order->total_minor,
                'currency' => 'NGN',
                'status' => OrderPayment::PENDING,
            ]);
        });

        if ($payment->authorization_url === null) {
            $url = $this->gateway->initialize(
                reference: $payment->reference,
                amountMinor: $payment->amount_minor,
                currency: $payment->currency,
                email: (string) $customer->email,
                callbackUrl: $callbackUrl,
                metadata: ['order' => $order->reference, 'kind' => 'tailor_order'],
            );
            $payment->authorization_url = $url;
            $payment->save();
        }

        return $payment;
    }

    /** Called by the Paystack webhook (and verify call) with the provider's own transaction record. */
    public function confirmPaid(string $reference, array $charge): OrderPayment
    {
        return DB::transaction(function () use ($reference, $charge) {
            $payment = OrderPayment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($payment->status === OrderPayment::PAID) {
                return $payment;
            }
            ChargeCheck::assertPays($charge, $payment->reference, $payment->amount_minor, $payment->currency);

            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $payment->forceFill([
                'status' => OrderPayment::PAID,
                'amount_paid_minor' => (int) $charge['amount'],
                'paid_at' => now(),
            ])->save();
            $order->payment_status = OrderPayment::PAID;
            $order->save();

            $this->notifier->notify($order->tailor_id, 'order_paid', 'Payment confirmed', "The customer has paid for order {$order->reference}. You can start work.", '/tailor/orders');
            $this->notifier->notify($order->customer_id, 'payment_confirmed', 'Payment confirmed', "We received your payment for order {$order->reference}.", '/orders/'.$order->reference);

            return $payment;
        });
    }

    /** A reference in the same shape as checkout references, so the webhook can recognise it. */
    public static function newReference(): string
    {
        return 'OSP-'.strtoupper(Str::random(16));
    }
}
