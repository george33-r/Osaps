<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;

/**
 * Buyer protection: a vendor's money is held until the buyer has the fabric. An order counts as
 * delivered only when the buyer confirms it or the vendor enters the buyer's delivery code. The
 * buyer then has a window (7 days by default) to report a problem; after that, with no open
 * report, the order completes and the payout becomes releasable.
 *
 * Paying a vendor takes two admins when two-person payouts are on: one approves, a different
 * one records the bank transfer.
 */
final class PayoutRules
{
    public const HELD = 'held';
    public const RELEASABLE = 'releasable';
    public const APPROVED = 'approved';
    public const PAID = 'paid';
    public const WITHHELD = 'withheld';

    public const LABELS = [
        self::HELD => 'Held until the buyer has it',
        self::RELEASABLE => 'Ready for payout',
        self::APPROVED => 'Payout approved',
        self::PAID => 'Paid to you',
        self::WITHHELD => 'Not payable (cancelled)',
    ];

    public function __construct(
        public readonly int $autoCompleteDays = 7,
        public readonly bool $twoPersonPayouts = true,
    ) {}

    public function shouldAutoComplete(string $status, ?DateTimeImmutable $deliveredAt, DateTimeImmutable $now, bool $disputeOpen = false): bool
    {
        return $status === FabricOrderStatus::DELIVERED
            && ! $disputeOpen
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

    /** @throws DomainRuleViolation */
    public function assertCanApprove(string $payoutStatus, bool $vendorVerified, bool $disputeOpen, bool $hasBankDetails): void
    {
        if ($payoutStatus !== self::RELEASABLE) {
            throw new DomainRuleViolation('This order is not waiting for a payout.', 'payout_not_releasable');
        }
        if (! $vendorVerified) {
            throw new DomainRuleViolation('This vendor is not verified. Verify or settle the shop before paying it.', 'payout_vendor_unverified');
        }
        if ($disputeOpen) {
            throw new DomainRuleViolation('A problem report is open on this order.', 'payout_dispute_open');
        }
        if (! $hasBankDetails) {
            throw new DomainRuleViolation('The vendor has not added bank details.', 'payout_no_bank');
        }
    }

    /** @throws DomainRuleViolation */
    public function assertCanMarkPaid(string $payoutStatus, ?int $approvedBy, int $actorId): void
    {
        if (! $this->twoPersonPayouts) {
            if (! in_array($payoutStatus, [self::RELEASABLE, self::APPROVED], true)) {
                throw new DomainRuleViolation('This order is not waiting for a payout.', 'payout_not_releasable');
            }

            return;
        }
        if ($payoutStatus !== self::APPROVED) {
            throw new DomainRuleViolation('Another admin must approve this payout before it is recorded as paid.', 'payout_not_approved');
        }
        if ($approvedBy === $actorId) {
            throw new DomainRuleViolation('You approved this payout. A different admin must send it and record it.', 'payout_needs_second_admin');
        }
    }
}
