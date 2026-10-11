<?php

namespace App\Domain\Negotiation;

use App\Domain\Support\DomainRuleViolation;

/**
 * A customer may only accept the exact terms they were shown. If the tailor revised the price
 * (or the delivery fee) between the customer loading the offer and pressing Accept, acceptance
 * is refused and the customer sees the new terms first.
 */
final class AcceptanceCheck
{
    public static function assertUnchanged(?int $expectedAmountMinor, int $currentAmountMinor, ?int $expectedDeliveryMinor = null, ?int $currentDeliveryMinor = null): void
    {
        if ($expectedAmountMinor === null) {
            throw new DomainRuleViolation('Refresh the offer and confirm the price you are accepting.', 'expected_amount_required');
        }
        if ($expectedAmountMinor !== $currentAmountMinor
            || ($expectedDeliveryMinor !== null && $expectedDeliveryMinor !== (int) ($currentDeliveryMinor ?? 0))) {
            throw new DomainRuleViolation('The tailor changed this offer while you were looking at it. Check the new terms before accepting.', 'offer_changed');
        }
    }
}
