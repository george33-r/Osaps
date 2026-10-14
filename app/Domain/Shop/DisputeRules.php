<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\DomainRuleViolation;

/**
 * A buyer can report a problem any time between payment and completion. While a report is open
 * the order cannot complete and the vendor's payout stays held. An admin settles it by refunding
 * the buyer (the order is cancelled) or by releasing the order to the vendor (it completes).
 */
final class DisputeRules
{
    public const OPEN = 'open';
    public const REFUNDED = 'refunded';
    public const RELEASED = 'released';

    public const REASONS = [
        'not_received' => 'I have not received it',
        'wrong_item' => 'Wrong fabric or colour',
        'damaged' => 'Damaged or faulty',
        'short' => 'Less than I paid for',
        'not_as_described' => 'Not as described',
        'other' => 'Something else',
    ];

    public const OPENABLE = [
        FabricOrderStatus::PAID,
        FabricOrderStatus::PACKED,
        FabricOrderStatus::OUT_FOR_DELIVERY,
        FabricOrderStatus::READY_FOR_PICKUP,
        FabricOrderStatus::DELIVERED,
    ];

    public const RESOLUTIONS = ['refund', 'release'];

    public static function isOpen(?string $disputeStatus): bool
    {
        return $disputeStatus === self::OPEN;
    }

    public static function canOpen(string $orderStatus, ?string $disputeStatus): bool
    {
        return $disputeStatus === null && in_array($orderStatus, self::OPENABLE, true);
    }

    /** @throws DomainRuleViolation */
    public static function assertCanOpen(string $orderStatus, ?string $disputeStatus, string $reason, string $details): void
    {
        if ($disputeStatus !== null) {
            throw new DomainRuleViolation('You have already reported a problem with this order. OSAPS support will reply.', 'dispute_exists');
        }
        if (! in_array($orderStatus, self::OPENABLE, true)) {
            throw new DomainRuleViolation($orderStatus === FabricOrderStatus::COMPLETED
                ? 'This order is complete. Contact OSAPS support if something is wrong.'
                : 'You can report a problem once the order is paid.', 'dispute_not_allowed');
        }
        if (! array_key_exists($reason, self::REASONS)) {
            throw new DomainRuleViolation('Choose what went wrong.', 'dispute_reason');
        }
        if (mb_strlen(trim($details)) < 10) {
            throw new DomainRuleViolation('Tell us a little more (at least 10 characters) so we can help.', 'dispute_details');
        }
    }

    /** @throws DomainRuleViolation */
    public static function assertCanResolve(?string $disputeStatus, string $resolution, ?string $note): void
    {
        if ($disputeStatus !== self::OPEN) {
            throw new DomainRuleViolation('This problem report is already settled.', 'dispute_not_open');
        }
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw new DomainRuleViolation('Choose refund or release.', 'dispute_resolution');
        }
        if (mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Write a short note on how this was settled. Both sides will see it.', 'dispute_note_required');
        }
    }

    /** @throws DomainRuleViolation */
    public static function assertNotBlocking(?string $disputeStatus, string $to): void
    {
        if (self::isOpen($disputeStatus) && $to === FabricOrderStatus::COMPLETED) {
            throw new DomainRuleViolation('A problem report is open on this order. OSAPS support will settle it first.', 'dispute_open');
        }
    }
}
