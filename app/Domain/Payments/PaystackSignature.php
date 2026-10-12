<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * Paystack signs each webhook with HMAC-SHA512 of the raw request body using your secret key,
 * sent in the x-paystack-signature header. Anything that fails this check is ignored.
 */
final class PaystackSignature
{
    public static function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha512', $rawBody, $secret);
    }

    public static function verify(string $rawBody, ?string $header, string $secret): bool
    {
        if ($secret === '' || $header === null || $header === '' || $rawBody === '') {
            return false;
        }

        return hash_equals(self::sign($rawBody, $secret), strtolower(trim($header)));
    }
}
