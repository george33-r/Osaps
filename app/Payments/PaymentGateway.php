<?php

namespace App\Payments;

/** The payment provider as the shop sees it. Paystack in production; a fake in tests. */
interface PaymentGateway
{
    public function enabled(): bool;

    /**
     * Start a payment and return the provider page the buyer is sent to.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function initialize(string $reference, int $amountMinor, string $currency, string $email, string $callbackUrl, array $metadata = []): string;

    /**
     * Ask the provider directly what happened to a payment. Returns its transaction "data" object.
     *
     * @return array<string,mixed>
     */
    public function verify(string $reference): array;

    public function verifyWebhook(string $rawBody, ?string $signature): bool;
}
