<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Support\DomainRuleViolation;

/**
 * Decides whether a provider's report of a charge really pays a checkout. A payment counts only
 * when the provider itself (verify call or signed webhook) says it succeeded, for this exact
 * reference, in the right currency, for at least the amount we asked for. The browser saying
 * "payment done" is never enough.
 */
final class ChargeCheck
{
    /**
     * @param  array<string,mixed>  $charge  The provider's "data" object for the transaction.
     *
     * @throws DomainRuleViolation
     */
    public static function assertPays(array $charge, string $reference, int $expectedMinor, string $currency): void
    {
        if (($charge['reference'] ?? null) !== $reference) {
            throw new DomainRuleViolation('The payment is for a different checkout.', 'reference_mismatch');
        }
        if (($charge['status'] ?? null) !== 'success') {
            throw new DomainRuleViolation('The payment has not gone through.', 'payment_not_successful');
        }
        if (strtoupper((string) ($charge['currency'] ?? '')) !== strtoupper($currency)) {
            throw new DomainRuleViolation('The payment was made in a different currency.', 'currency_mismatch');
        }
        $amount = $charge['amount'] ?? null;
        if (! is_int($amount) && ! (is_string($amount) && ctype_digit($amount))) {
            throw new DomainRuleViolation('The payment amount could not be read.', 'amount_unreadable');
        }
        if ((int) $amount < $expectedMinor) {
            throw new DomainRuleViolation('The amount paid is less than the order total.', 'amount_mismatch');
        }
    }

    /** A checkout reference we generate: OSP- followed by 16 upper-case letters and digits. */
    public static function isOurReference(string $reference): bool
    {
        return (bool) preg_match('/\AOSP-[A-Z0-9]{16}\z/', $reference);
    }
}
