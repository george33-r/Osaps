<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use DateTimeImmutable;

/**
 * Buyer protection: a vendor's money is held until the buyer confirms they received the fabric.
 * If the buyer says nothing for a few days after the vendor marks it delivered, the order
 * completes on its own so honest vendors are not left waiting.
 */
final class PayoutRules
{
    public const HELD = 'held';
    public const RELEASABLE = 'releasable';
    public const PAID = 'paid';
    public const WITHHELD = 'withheld';

    public function __construct(public readonly int $autoCompleteDays = 3) {}

    public function shouldAutoComplete(string $status, ?DateTimeImmutable $deliveredAt, DateTimeImmutable $now): bool
    {
        return $status === FabricOrderStatus::DELIVERED
            && $deliveredAt !== null
            && $now >= $deliveredAt->modify("+{$this->autoCompleteDays} days");
    }

    public static function forStatus(string $status): string
    {
        return match ($status) {
            FabricOrderStatus::COMPLETED => self::RELEASABLE,
            FabricOrderStatus::CANCELLED => self::WITHHELD,
            default => self::HELD,
        };
    }
}
