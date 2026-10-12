<?php

namespace App\Payments;

use App\Domain\Payments\PaystackSignature;

/** In-memory gateway for automated tests. Never bound outside tests. */
class FakeGateway implements PaymentGateway
{
    /** @var array<string, array<string,mixed>> what "Paystack" will report per reference */
    public array $charges = [];

    /** @var list<array<string,mixed>> */
    public array $initialized = [];

    public function __construct(public string $secret = 'sk_test_fake') {}

    public function enabled(): bool
    {
        return true;
    }

    public function initialize(string $reference, int $amountMinor, string $currency, string $email, string $callbackUrl, array $metadata = []): string
    {
        $this->initialized[] = compact('reference', 'amountMinor', 'currency', 'email', 'callbackUrl');

        return 'https://checkout.paystack.com/fake-'.$reference;
    }

    public function verify(string $reference): array
    {
        return $this->charges[$reference] ?? ['reference' => $reference, 'status' => 'abandoned'];
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return PaystackSignature::verify($rawBody, $signature, $this->secret);
    }

    public function pay(string $reference, int $amountMinor, string $currency = 'NGN'): void
    {
        $this->charges[$reference] = ['reference' => $reference, 'status' => 'success', 'amount' => $amountMinor, 'currency' => $currency];
    }
}
