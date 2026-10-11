<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Immutable money value held in minor units (kobo for NGN).
 * Never use floats for stored amounts; integer minor units only.
 */
final class Money
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency = 'NGN',
    ) {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 code.');
        }
    }

    public static function minor(int $minor, string $currency = 'NGN'): self
    {
        return new self($minor, $currency);
    }

    /** Whole naira (major units) to Money. Accepts int only to avoid float rounding. */
    public static function major(int $major, string $currency = 'NGN'): self
    {
        return new self($major * 100, $currency);
    }

    public static function zero(string $currency = 'NGN'): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Percentage expressed in basis points (250 = 2.5%), rounded half-up to the nearest minor unit.
     */
    public function percentBps(int $bps): self
    {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException('Basis points must be between 0 and 10000.');
        }

        return new self(intdiv($this->minor * $bps + 5_000, 10_000), $this->currency);
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function format(): string
    {
        $symbol = $this->currency === 'NGN' ? '₦' : $this->currency.' ';
        $major = intdiv($this->minor, 100);
        $rest = $this->minor % 100;

        return $symbol.number_format($major).($rest > 0 ? sprintf('.%02d', $rest) : '');
    }

    /** @return array{amount_minor:int,currency:string,formatted:string} */
    public function toArray(): array
    {
        return ['amount_minor' => $this->minor, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }
    }
}
