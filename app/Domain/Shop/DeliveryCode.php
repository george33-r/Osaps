<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\DomainRuleViolation;

/**
 * Proof of handover. Each paid order gets a 6-digit code that only the buyer can see. The vendor
 * (or their rider) can mark the order received only by typing that code, which the buyer gives
 * when the fabric is in their hands. The buyer can also confirm in the app themselves.
 */
final class DeliveryCode
{
    public const LENGTH = 6;

    public const MAX_ATTEMPTS = 5;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public static function normalise(string $given): string
    {
        return preg_replace('/\D/', '', $given) ?? '';
    }

    /** @throws DomainRuleViolation */
    public static function assertMatches(?string $expected, ?string $given, int $wrongAttempts): void
    {
        if ($expected === null || $expected === '') {
            throw new DomainRuleViolation('This order has no delivery code. Ask the buyer to confirm in the app.', 'delivery_code_missing');
        }
        if ($wrongAttempts >= self::MAX_ATTEMPTS) {
            throw new DomainRuleViolation('Too many wrong codes. Ask the buyer to confirm in the app, or contact OSAPS support.', 'delivery_code_locked');
        }
        $given = self::normalise((string) $given);
        if ($given === '') {
            throw new DomainRuleViolation('Enter the 6-digit code the buyer shows you when they receive the fabric.', 'delivery_code_required');
        }
        if (! hash_equals($expected, $given)) {
            $left = self::MAX_ATTEMPTS - $wrongAttempts - 1;
            throw new DomainRuleViolation($left > 0
                ? "That code is not right. {$left} tries left."
                : 'That code is not right. Ask the buyer to confirm in the app, or contact OSAPS support.', 'delivery_code_wrong');
        }
    }
}
