<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PayoutRules;
use App\Domain\Support\DomainRuleViolation;

/**
 * Money goes back to the buyer through Paystack, only for orders that were paid and then
 * cancelled, never more than the order's total, and never after the vendor was paid for it.
 */
final class RefundRules
{
    public const PENDING = 'pending';
    public const PROCESSED = 'processed';
    public const FAILED = 'failed';

    public const LABELS = [
        self::PENDING => 'Refund on its way',
        self::PROCESSED => 'Refunded',
        self::FAILED => 'Refund failed, OSAPS is retrying',
    ];

    /** @param int $committedMinor refunds already processed or still pending */
    public static function remaining(int $orderTotalMinor, int $committedMinor): int
    {
        return max(0, $orderTotalMinor - $committedMinor);
    }

    /** @throws DomainRuleViolation */
    public static function assertCanRefund(string $orderStatus, bool $checkoutWasPaid, string $payoutStatus, int $amountMinor, int $remainingMinor): void
    {
        if (! $checkoutWasPaid) {
            throw new DomainRuleViolation('This order was never paid, so there is nothing to refund.', 'refund_not_paid');
        }
        if ($orderStatus !== FabricOrderStatus::CANCELLED) {
            throw new DomainRuleViolation('Cancel the order (or settle its problem report as a refund) before refunding.', 'refund_order_not_cancelled');
        }
        if ($payoutStatus === PayoutRules::PAID || $payoutStatus === PayoutRules::APPROVED) {
            throw new DomainRuleViolation('The vendor payout for this order was already approved or sent. Recover it from the vendor first.', 'refund_payout_sent');
        }
        if ($remainingMinor <= 0) {
            throw new DomainRuleViolation('This order has already been refunded in full.', 'refund_done');
        }
        if ($amountMinor < 100 || $amountMinor > $remainingMinor) {
            throw new DomainRuleViolation('Refund between ₦1 and the amount still owed to the buyer.', 'refund_amount');
        }
    }

    /** Paystack's refund status words, mapped to ours. Unknown words leave the refund pending. */
    public static function fromProvider(?string $status): string
    {
        return match ($status) {
            'processed' => self::PROCESSED,
            'failed' => self::FAILED,
            default => self::PENDING,
        };
    }
}
