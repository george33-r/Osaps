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

    /**
     * Ask the provider to send money back to the buyer's card or account.
     * Returns the provider's refund "data" object (its id and status). Throws on a clear refusal;
     * throws a DomainRuleViolation with rule "payment_outcome_unknown" when the provider did not answer, so nobody retries blindly.
     *
     * @return array<string,mixed>
     */
    public function refund(string $reference, int $amountMinor, string $currency, string $note): array;
}
