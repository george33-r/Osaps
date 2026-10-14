<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\ChargeCheck;
use App\Domain\Support\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Payments\PaymentGateway;
use App\Services\CheckoutService;
use App\Services\TrustService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Paystack calls this when a payment succeeds (set the URL in your Paystack dashboard:
 * Settings → API Keys & Webhooks → https://YOUR-DOMAIN/api/v1/webhooks/paystack).
 *
 * Unsigned or badly signed calls are refused. Each event is stored once; replays are acknowledged
 * and ignored. Paystack expects a quick 200, so unknown events are acknowledged too.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, CheckoutService $checkouts, TrustService $trust): Response
    {
        $raw = $request->getContent();
        if (strlen($raw) > 100_000 || ! $gateway->verifyWebhook($raw, $request->header('x-paystack-signature'))) {
            return response('invalid signature', 401);
        }

        $payload = json_decode($raw, true);
        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference']
            : (is_string($data['transaction_reference'] ?? null) ? $data['transaction_reference'] : null);

        $key = hash('sha256', $raw);
        try {
            $record = PaymentEvent::query()->create([
                'provider' => 'paystack',
                'event_key' => $key,
                'reference' => $reference,
                'event' => mb_substr($event, 0, 48),
                'signature_valid' => true,
                // Keep what we need to audit the payment, not the card or customer details.
                'payload' => ['event' => $event, 'reference' => $reference, 'status' => $data['status'] ?? null, 'amount' => $data['amount'] ?? null, 'currency' => $data['currency'] ?? null, 'id' => $data['id'] ?? null],
            ]);
        } catch (UniqueConstraintViolationException) {
            // A replay. Ignore it, unless the first delivery crashed before finishing: then try again.
            $record = PaymentEvent::query()->where('event_key', $key)->first();
            if ($record === null || ! in_array($record->result, [null, 'error'], true)) {
                return response('duplicate', 200);
            }
        }

        $result = 'ignored';
        if (str_starts_with($event, 'refund.')) {
            try {
                $result = $trust->applyRefundEvent($event, $data);
            } catch (\Throwable $e) {
                $record->result = 'error';
                $record->save();
                Log::error('Paystack refund webhook failed', ['reference' => $reference, 'error' => $e->getMessage()]);

                return response('error', 500);
            }
        } elseif ($event === 'charge.success' && $reference !== null && ChargeCheck::isOurReference($reference)) {
            try {
                $checkout = $checkouts->confirmPaid($reference, $data);
                $result = $checkout->payment_status;
            } catch (ModelNotFoundException) {
                $result = 'unknown_reference';
            } catch (DomainRuleViolation $e) {
                $result = $e->rule;
                Log::warning('Paystack webhook refused', ['reference' => $reference, 'rule' => $e->rule]);
            } catch (\Throwable $e) {
                // Unexpected failure: record it and answer 500 so Paystack retries this event.
                $record->result = 'error';
                $record->save();
                Log::error('Paystack webhook failed', ['reference' => $reference, 'error' => $e->getMessage()]);

                return response('error', 500);
            }
        }
        $record->result = $result;
        $record->save();

        return response('ok', 200);
    }
}
