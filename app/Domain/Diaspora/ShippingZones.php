<?php

declare(strict_types=1);

namespace App\Domain\Diaspora;

use App\Domain\Support\DomainRuleViolation;

/** International shipping, priced per destination zone. Values come from config; they are estimates set by OSAPS. */
final class ShippingZones
{
    public const COUNTRIES = [
        'GB' => 'uk', 'IE' => 'eu',
        'US' => 'us', 'CA' => 'ca',
        'DE' => 'eu', 'FR' => 'eu', 'NL' => 'eu', 'BE' => 'eu', 'IT' => 'eu', 'ES' => 'eu', 'SE' => 'eu', 'NO' => 'eu', 'DK' => 'eu', 'FI' => 'eu', 'PT' => 'eu', 'AT' => 'eu', 'CH' => 'eu', 'PL' => 'eu',
        'GH' => 'africa', 'ZA' => 'africa', 'KE' => 'africa', 'CM' => 'africa', 'SN' => 'africa', 'CI' => 'africa',
        'AE' => 'other', 'AU' => 'other', 'QA' => 'other', 'SA' => 'other', 'CN' => 'other', 'IN' => 'other', 'JP' => 'other', 'BR' => 'other', 'NZ' => 'other',
    ];

    /** @param array<string,int> $feesMinor zone => fee per vendor parcel, naira minor units */
    public function __construct(private readonly array $feesMinor) {}

    /** @throws DomainRuleViolation */
    public function feeFor(string $country): int
    {
        $country = strtoupper($country);
        if ($country === 'NG') {
            throw new DomainRuleViolation('For a Nigerian address, choose delivery instead.', 'country_is_nigeria');
        }
        $zone = self::COUNTRIES[$country] ?? null;
        $fee = $zone !== null ? ($this->feesMinor[$zone] ?? null) : null;
        if (! is_int($fee) || $fee <= 0) {
            throw new DomainRuleViolation('We do not ship to that country yet.', 'country_not_served');
        }

        return $fee;
    }
}
