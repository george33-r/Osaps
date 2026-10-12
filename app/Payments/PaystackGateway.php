<?php

namespace App\Payments;

use App\Domain\Payments\PaystackSignature;
use App\Domain\Support\DomainRuleViolation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack over its REST API (https://paystack.com/docs/api/transaction/).
 * The secret key is read from config on the server and is never sent to the browser.
 */
class PaystackGateway implements PaymentGateway
{
    /** @param list<string> $channels */
    public function __construct(
        private readonly ?string $secret,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly array $channels = [],
    ) {}

    public function enabled(): bool
    {
        return is_string($this->secret) && str_starts_with($this->secret, 'sk_');
    }

    public function initialize(string $reference, int $amountMinor, string $currency, string $email, string $callbackUrl, array $metadata = []): string
    {
        $this->assertEnabled();
        $body = [
            'email' => $email,
            'amount' => $amountMinor, // Paystack takes kobo, which is our minor unit.
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];
        if ($this->channels !== []) {
            $body['channels'] = $this->channels;
        }

        $json = $this->call('post', '/transaction/initialize', $body);
        $url = $json['data']['authorization_url'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new DomainRuleViolation('Paystack did not start the payment. Please try again.', 'payment_start_failed');
        }

        return $url;
    }

    public function verify(string $reference): array
    {
        $this->assertEnabled();
        $json = $this->call('get', '/transaction/verify/'.rawurlencode($reference));
        $data = $json['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return $this->enabled() && PaystackSignature::verify($rawBody, $signature, (string) $this->secret);
    }

    /** @return array<string,mixed> */
    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $res = Http::withToken((string) $this->secret)
                ->acceptJson()
                ->timeout($this->timeout)
                ->{$method}(rtrim($this->baseUrl, '/').$path, $body);
        } catch (ConnectionException $e) {
            Log::warning('Paystack unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new DomainRuleViolation('We could not reach Paystack. Please try again in a moment.', 'payment_provider_unreachable');
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json) || ($json['status'] ?? false) !== true) {
            // Log the provider's message, never the key.
            Log::warning('Paystack error', ['path' => $path, 'status' => $res->status(), 'message' => is_array($json) ? ($json['message'] ?? null) : null]);
            throw new DomainRuleViolation('Paystack could not process this right now. Please try again.', 'payment_provider_error');
        }

        return $json;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new DomainRuleViolation('Online payment is not switched on yet.', 'payments_disabled');
        }
    }
}
