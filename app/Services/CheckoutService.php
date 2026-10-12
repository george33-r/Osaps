<?php

namespace App\Services;

use App\Domain\Payments\ChargeCheck;
use App\Domain\Shop\CartLine;
use App\Domain\Shop\CartPricing;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PriceOfferStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\Checkout;
use App\Models\Fabric;
use App\Models\FabricOrder;
use App\Models\PriceOffer;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fabric checkout, stock and payment confirmation.
 *
 *  - Prices always come from the database (and agreed offers), never from the browser.
 *  - The buyer sends the total they saw; if anything changed, checkout stops and shows the new quote.
 *  - Stock is reserved while the buyer pays and released if they do not.
 *  - Only the payment provider (verify call or signed webhook) can mark a checkout paid, once.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly PaymentGateway $gateway,
        private readonly ShopOfferService $offers,
    ) {}

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @return array<string,mixed>
     */
    public function quote(User $buyer, array $items, string $fulfilment): array
    {
        $this->releaseExpired();
        $this->offers->expireStale();
        $fabrics = Fabric::query()->with('vendor')->whereIn('id', array_column($items, 'fabric_id'))->get()->keyBy('id');

        return $this->pricing->quote($this->lines($buyer, $items, $fabrics), $fulfilment);
    }

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @param  array{name:string, phone:string, email:string}  $contact
     * @param  array<string,string>|null  $address
     */
    public function create(User $buyer, array $items, string $fulfilment, array $contact, ?array $address, int $expectedTotalMinor, string $callbackUrl): Checkout
    {
        if (! $this->gateway->enabled()) {
            throw new DomainRuleViolation('Online payment is not switched on yet. Please check back soon.', 'payments_disabled');
        }
        $this->releaseExpired();
        $this->offers->expireStale();
        // Starting again (e.g. after backing out of the Paystack page) replaces any earlier unpaid
        // checkout, so its stock and agreed prices are free for this one.
        foreach (Checkout::query()->where('customer_id', $buyer->id)->where('payment_status', Checkout::PENDING)->get() as $old) {
            $this->cancel($old, 'customer', 'Replaced by a newer checkout.', $buyer);
        }

        $checkout = DB::transaction(function () use ($buyer, $items, $fulfilment, $contact, $address, $expectedTotalMinor) {
            // Lock in id order so two checkouts for the same fabrics cannot deadlock.
            $ids = array_values(array_unique(array_map('intval', array_column($items, 'fabric_id'))));
            sort($ids);
            $fabrics = Fabric::query()->with('vendor')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $quote = $this->pricing->quote($this->lines($buyer, $items, $fabrics, forCheckout: true), $fulfilment);
            if (! $quote['ok']) {
                throw new DomainRuleViolation($quote['blocking'][0]['message'] ?? 'Your cart needs attention.', 'cart_invalid');
            }
            if ($quote['total'] !== $expectedTotalMinor) {
                throw new DomainRuleViolation('Prices or delivery changed since you looked. Review the new total before paying.', 'cart_changed');
            }

            $checkout = new Checkout;
            $checkout->reference = $this->newReference();
            $checkout->customer_id = $buyer->id;
            $checkout->fulfilment = $fulfilment;
            $checkout->contact = $contact;
            $checkout->address = $fulfilment === 'delivery' ? $address : null;
            $checkout->subtotal_minor = $quote['subtotal'];
            $checkout->delivery_minor = $quote['delivery'];
            $checkout->service_fee_minor = $quote['service_fee'];
            $checkout->total_minor = $quote['total'];
            $checkout->currency = $this->pricing->currency;
            $checkout->payment_status = Checkout::PENDING;
            $checkout->expires_at = now()->addMinutes((int) config('marketplace.shop.checkout_hold_minutes', 60));
            $checkout->save();

            $fees = CartPricing::allocate($quote['service_fee'], array_column($quote['vendors'], 'subtotal'));
            $commissionBps = (int) config('marketplace.shop.vendor_commission_bps', 500);

            foreach ($quote['vendors'] as $i => $v) {
                $order = new FabricOrder;
                $order->reference = 'FAB-'.strtoupper(Str::random(8));
                $order->checkout_id = $checkout->id;
                $order->customer_id = $buyer->id;
                $order->fabric_vendor_id = $v['vendor_id'];
                $order->status = FabricOrderStatus::PENDING_PAYMENT;
                $order->fulfilment = $fulfilment;
                $order->subtotal_minor = $v['subtotal'];
                $order->delivery_minor = $v['delivery'];
                $order->service_fee_minor = $fees[$i];
                $order->total_minor = $v['subtotal'] + $v['delivery'] + $fees[$i];
                $order->vendor_commission_minor = intdiv($v['subtotal'] * $commissionBps + 5_000, 10_000);
                $order->save();
                $order->events()->create(['actor_id' => $buyer->id, 'actor_role' => 'customer', 'from_status' => null, 'to_status' => FabricOrderStatus::PENDING_PAYMENT, 'note' => null]);

                foreach ($v['lines'] as $line) {
                    $order->items()->create([
                        'fabric_id' => $line['fabric_id'], 'price_offer_id' => $line['price_offer_id'],
                        'name' => $line['name'], 'unit' => $line['unit'], 'quantity' => $line['quantity'],
                        'list_unit_minor' => $line['list_unit_minor'], 'unit_minor' => $line['unit_minor'],
                        'line_total_minor' => $line['line_total_minor'],
                    ]);
                    // Hold the stock while the buyer pays.
                    Fabric::query()->whereKey($line['fabric_id'])->increment('reserved_quantity', $line['quantity']);
                }
            }

            return $checkout;
        });

        try {
            $url = $this->gateway->initialize(
                reference: $checkout->reference,
                amountMinor: $checkout->total_minor,
                currency: $checkout->currency,
                email: $buyer->email,
                callbackUrl: $callbackUrl,
                metadata: ['checkout' => $checkout->reference, 'orders' => $checkout->orders()->pluck('reference')->all()],
            );
        } catch (\Throwable $e) {
            // The payment page never opened: give the stock straight back.
            $this->cancel($checkout, 'system', 'Payment could not be started.');
            throw $e instanceof DomainRuleViolation ? $e : new DomainRuleViolation('Payment could not be started. Please try again.', 'payment_start_failed');
        }

        $checkout->authorization_url = $url;
        $checkout->save();

        return $checkout;
    }

    /** Ask the provider about a payment and record it if it went through. Safe to call any number of times. */
    public function verify(Checkout $checkout): Checkout
    {
        if ($checkout->payment_status === Checkout::PAID || $checkout->payment_status === Checkout::NEEDS_REFUND) {
            return $checkout;
        }
        $charge = $this->gateway->verify($checkout->reference);
        if (($charge['status'] ?? null) !== 'success') {
            return $checkout->fresh();
        }

        return $this->confirmPaid($checkout->reference, $charge);
    }

    /**
     * Record a successful charge. Idempotent: a webhook and a verify call racing each other,
     * or a replayed webhook, mark the checkout paid exactly once.
     *
     * @param  array<string,mixed>  $charge
     */
    public function confirmPaid(string $reference, array $charge): Checkout
    {
        return DB::transaction(function () use ($reference, $charge) {
            $checkout = Checkout::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if (in_array($checkout->payment_status, [Checkout::PAID, Checkout::NEEDS_REFUND], true)) {
                return $checkout;
            }
            ChargeCheck::assertPays($charge, $checkout->reference, $checkout->total_minor, $checkout->currency);

            $orders = $checkout->orders()->with('items')->lockForUpdate()->get();
            $items = $orders->flatMap->items;
            $stillHeld = $checkout->payment_status === Checkout::PENDING;

            $ids = $items->pluck('fabric_id')->unique()->sort()->values()->all();
            $fabrics = Fabric::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if (! $stillHeld) {
                // The hold lapsed before the money arrived. Take the stock again if it is still there.
                $short = $items->first(fn ($it) => ($fabrics[$it->fabric_id]?->availableQuantity() ?? 0) < $it->quantity);
                if ($short !== null) {
                    $checkout->payment_status = Checkout::NEEDS_REFUND;
                    $checkout->amount_paid_minor = (int) $charge['amount'];
                    $checkout->paid_at = now();
                    $checkout->save();
                    foreach ($orders as $order) {
                        $this->moveOrder($order, FabricOrderStatus::CANCELLED, null, 'system', 'Paid after the stock hold ended and the fabric had sold out. A refund is due.', force: true);
                    }
                    Log::warning('Checkout paid after stock ran out; refund needed', ['reference' => $reference]);

                    return $checkout;
                }
                foreach ($items as $it) {
                    $fabrics[$it->fabric_id]->increment('reserved_quantity', $it->quantity);
                }
            }

            foreach ($items as $it) {
                // Reserved stock becomes sold stock.
                Fabric::query()->whereKey($it->fabric_id)->update([
                    'stock_quantity' => DB::raw('stock_quantity - '.(int) $it->quantity),
                    'reserved_quantity' => DB::raw('reserved_quantity - '.(int) $it->quantity),
                ]);
                if ($it->price_offer_id !== null) {
                    $offer = PriceOffer::query()->lockForUpdate()->find($it->price_offer_id);
                    if ($offer !== null && PriceOfferStatus::can($offer->status, PriceOfferStatus::USED, 'system')) {
                        $offer->status = PriceOfferStatus::USED;
                        $offer->used_at = now();
                        $offer->save();
                    }
                }
            }

            $checkout->payment_status = Checkout::PAID;
            $checkout->amount_paid_minor = (int) $charge['amount'];
            $checkout->paid_at = now();
            $checkout->save();

            foreach ($orders as $order) {
                $this->moveOrder($order, FabricOrderStatus::PAID, null, 'system', 'Payment confirmed by Paystack.', force: $order->status !== FabricOrderStatus::PENDING_PAYMENT);
            }

            return $checkout;
        });
    }

    /** Buyer gives up before paying, or the system abandons a checkout. Releases held stock. */
    public function cancel(Checkout $checkout, string $role, string $reason, ?User $actor = null): Checkout
    {
        return DB::transaction(function () use ($checkout, $role, $reason, $actor) {
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            if ($checkout->payment_status !== Checkout::PENDING) {
                return $checkout;
            }
            $this->release($checkout);
            $checkout->payment_status = $role === 'system' ? Checkout::EXPIRED : Checkout::CANCELLED;
            $checkout->save();
            foreach ($checkout->orders()->lockForUpdate()->get() as $order) {
                $this->moveOrder($order, FabricOrderStatus::CANCELLED, $actor, $role, $reason);
            }

            return $checkout;
        });
    }

    /** Release stock held by checkouts whose payment window has passed. */
    public function releaseExpired(): int
    {
        $n = 0;
        foreach (Checkout::query()->where('payment_status', Checkout::PENDING)->where('expires_at', '<', now())->limit(100)->get() as $c) {
            $this->cancel($c, 'system', 'Payment was not completed in time.');
            $n++;
        }

        return $n;
    }

    /** Vendor or buyer moves an order along. */
    public function transition(User $user, FabricOrder $order, string $to, ?string $note): FabricOrder
    {
        return DB::transaction(function () use ($user, $order, $to, $note) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            $role = match (true) {
                $user->isAdmin() => 'admin',
                $order->vendor?->user_id === $user->id => 'vendor',
                $order->customer_id === $user->id => 'customer',
                default => throw new DomainRuleViolation('This order is not yours.', 'forbidden'),
            };
            $dispatch = [FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP];
            if (in_array($to, $dispatch, true) && $to !== FabricOrderStatus::dispatchStep($order->fulfilment)) {
                throw new DomainRuleViolation($order->fulfilment === 'pickup'
                    ? 'The buyer is collecting this order. Mark it ready for pickup.'
                    : 'The buyer chose delivery. Mark it out for delivery.', 'wrong_dispatch_step');
            }
            if ($to === FabricOrderStatus::CANCELLED && $role === 'customer') {
                throw new DomainRuleViolation('To cancel a paid order, contact OSAPS support.', 'cancel_needs_support');
            }
            $this->moveOrder($order, $to, $user, $role, $note);

            return $order->fresh(['items', 'events', 'vendor', 'checkout']);
        });
    }

    private function moveOrder(FabricOrder $order, string $to, ?User $actor, string $role, ?string $note, bool $force = false): void
    {
        $from = $order->status;
        if ($from === $to) {
            return;
        }
        if (! $force) {
            FabricOrderStatus::assert($from, $to, $role);
        }
        $order->status = $to;
        $order->save();
        $order->events()->create(['actor_id' => $actor?->id, 'actor_role' => $role, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    private function release(Checkout $checkout): void
    {
        $items = $checkout->orders()->with('items')->get()->flatMap->items;
        $ids = $items->pluck('fabric_id')->unique()->sort()->values()->all();
        Fabric::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        foreach ($items as $it) {
            Fabric::query()->whereKey($it->fabric_id)->update([
                'reserved_quantity' => DB::raw('CASE WHEN reserved_quantity >= '.(int) $it->quantity.' THEN reserved_quantity - '.(int) $it->quantity.' ELSE 0 END'),
            ]);
        }
    }

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @param  Collection<int, Fabric>  $fabrics
     * @return list<CartLine>
     */
    private function lines(User $buyer, array $items, Collection $fabrics, bool $forCheckout = false): array
    {
        $offerIds = array_values(array_filter(array_map(fn ($i) => isset($i['price_offer_id']) ? (int) $i['price_offer_id'] : null, $items)));
        $offers = $offerIds === [] ? collect() : PriceOffer::query()->whereIn('id', $offerIds)->when($forCheckout, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');

        // An agreed price may back only one unpaid checkout at a time. The buyer's own unpaid
        // checkout does not count: creating a new one cancels it first.
        $busy = $offerIds === [] ? [] : \App\Models\FabricOrderItem::query()
            ->whereIn('price_offer_id', $offerIds)
            ->whereHas('fabricOrder', fn ($q) => $q->where('status', FabricOrderStatus::PENDING_PAYMENT)->where('customer_id', '!=', $buyer->id))
            ->pluck('price_offer_id')->all();

        $lines = [];
        foreach ($items as $item) {
            $fabric = $fabrics[(int) $item['fabric_id']] ?? null;
            $offerId = isset($item['price_offer_id']) ? (int) $item['price_offer_id'] : null;
            $offer = $offerId !== null ? ($offers[$offerId] ?? null) : null;
            $usable = $offer !== null && $fabric !== null
                && $offer->buyer_id === $buyer->id
                && $offer->fabric_id === $fabric->id
                && $offer->isUsable()
                && ! in_array($offer->id, $busy, true);

            $lines[] = new CartLine(
                fabricId: (int) $item['fabric_id'],
                vendorId: (int) ($fabric?->fabric_vendor_id ?? 0),
                vendorName: (string) ($fabric?->vendor?->business_name ?? 'Unknown vendor'),
                fabricName: (string) ($fabric?->name ?? 'A fabric'),
                unit: (string) ($fabric?->unit ?? 'unit'),
                quantity: (int) $item['quantity'],
                listUnitMinor: (int) ($fabric?->price_minor ?? 0),
                available: $fabric?->availableQuantity() ?? 0,
                minQuantity: (int) ($fabric?->min_order_quantity ?? 1),
                listed: $fabric !== null && $fabric->status === 'active' && $fabric->vendor?->user_id !== $buyer->id,
                offerId: $offerId,
                offerUnitMinor: $offer?->unit_minor,
                offerQuantity: $offer?->quantity,
                offerUsable: $usable,
            );
        }

        return $lines;
    }

    private function newReference(): string
    {
        do {
            $ref = 'OSP-'.strtoupper(Str::random(16));
            $ref = preg_replace('/[^A-Z0-9-]/', 'X', $ref);
        } while (Checkout::query()->where('reference', $ref)->exists());

        return $ref;
    }
}
