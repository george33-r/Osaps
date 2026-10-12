<?php

declare(strict_types=1);

namespace App\Domain\Diaspora;

/**
 * Approximate foreign prices for shoppers abroad. Every payment is charged in naira; these numbers
 * only help people read a price, and are always labelled as approximate.
 *
 * Rates are naira per one unit of the foreign currency (e.g. GBP => 2050 means £1 ≈ ₦2,050).
 */
final class CurrencyDisplay
{
    public const SYMBOLS = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CAD' => 'CA$'];

    /** @param array<string, int|float> $nairaPerUnit */
    public function __construct(private readonly array $nairaPerUnit) {}

    /** @return array<string, float> only valid, positive rates */
    public function rates(): array
    {
        $out = [];
        foreach ($this->nairaPerUnit as $code => $rate) {
            if (isset(self::SYMBOLS[$code]) && is_numeric($rate) && $rate > 0) {
                $out[$code] = (float) $rate;
            }
        }

        return $out;
    }

    /** Naira minor units → foreign minor units (cents/pence), rounded to the nearest. */
    public function convert(int $nairaMinor, string $code): ?int
    {
        $rate = $this->rates()[$code] ?? null;

        return $rate === null ? null : (int) round($nairaMinor / $rate);
    }
}
