<?php

declare(strict_types=1);

namespace App\Domain\AsoEbi;

use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;

/**
 * Aṣọ-ẹbí group orders: one fabric, one colour, everybody pays their own share.
 *
 *  - The organiser sets yards per guest, how many guests they expect and a deadline.
 *  - If the organiser agreed a bulk price with the vendor (an accepted price offer), that price
 *    applies to guests until the agreed quantity is used up; after that the list price applies.
 *  - A group with paid guests cannot simply be cancelled: money has to be returned first.
 */
final class AsoEbiRules
{
    public const FULFILMENTS = ['deliver_each', 'pickup_organizer'];

    public function __construct(
        public readonly int $maxGuests = 500,
        public readonly int $maxYardsPerGuest = 50,
        public readonly int $maxDeadlineDays = 120,
    ) {}

    /** @throws DomainRuleViolation */
    public function assertCanCreate(
        int $yardsPerGuest,
        int $minOrderQuantity,
        int $targetGuests,
        DateTimeImmutable $deadline,
        DateTimeImmutable $eventDate,
        DateTimeImmutable $now,
        bool $fabricListed,
        string $fulfilment,
    ): void {
        if (! $fabricListed) {
            throw new DomainRuleViolation('This fabric is no longer listed.', 'fabric_unavailable');
        }
        if ($yardsPerGuest < max(1, $minOrderQuantity) || $yardsPerGuest > $this->maxYardsPerGuest) {
            throw new DomainRuleViolation('Set between '.max(1, $minOrderQuantity)." and {$this->maxYardsPerGuest} per guest.", 'yards_out_of_range');
        }
        if ($targetGuests < 2 || $targetGuests > $this->maxGuests) {
            throw new DomainRuleViolation("Invite between 2 and {$this->maxGuests} guests.", 'guests_out_of_range');
        }
        if ($deadline <= $now) {
            throw new DomainRuleViolation('The payment deadline must be in the future.', 'deadline_past');
        }
        if ($deadline > $now->modify("+{$this->maxDeadlineDays} days")) {
            throw new DomainRuleViolation("The deadline can be at most {$this->maxDeadlineDays} days away.", 'deadline_too_far');
        }
        if ($eventDate < $deadline->setTime(0, 0)) {
            throw new DomainRuleViolation('The payment deadline must come before the event.', 'event_before_deadline');
        }
        if (! in_array($fulfilment, self::FULFILMENTS, true)) {
            throw new DomainRuleViolation('Choose delivery to each guest or collection by the organiser.', 'fulfilment_invalid');
        }
    }

    /**
     * The agreed bulk price covers this share only if it still fits in the agreed quantity.
     * $committedYards counts yards in paid and in-progress shares.
     */
    public function dealApplies(?int $dealUnitMinor, ?int $dealQuantity, int $committedYards, int $shareYards): bool
    {
        return $dealUnitMinor !== null && $dealQuantity !== null && $committedYards + $shareYards <= $dealQuantity;
    }

    /** @throws DomainRuleViolation */
    public function assertCanJoin(string $status, DateTimeImmutable $deadline, DateTimeImmutable $now, int $yards, int $minOrderQuantity): void
    {
        if ($status !== AsoEbiStatus::OPEN || $now >= $deadline) {
            throw new DomainRuleViolation('This aṣọ-ẹbí is no longer taking payments. Ask the organiser.', 'group_closed');
        }
        if ($yards < max(1, $minOrderQuantity) || $yards > $this->maxYardsPerGuest) {
            throw new DomainRuleViolation('Choose between '.max(1, $minOrderQuantity)." and {$this->maxYardsPerGuest}.", 'yards_out_of_range');
        }
    }

    /** @throws DomainRuleViolation */
    public function assertCanCancel(int $paidShares): void
    {
        if ($paidShares > 0) {
            throw new DomainRuleViolation('Guests have already paid. Close the group instead, or contact OSAPS support to arrange refunds.', 'group_has_payments');
        }
    }

    /** @return array{paid:int, target:int, percent:int} */
    public function progress(int $paidShares, int $targetGuests): array
    {
        $target = max(1, $targetGuests);

        return ['paid' => $paidShares, 'target' => $targetGuests, 'percent' => min(100, intdiv($paidShares * 100, $target))];
    }

    /** Map the group's choice onto how each guest's fabric is fulfilled. */
    public static function guestFulfilment(string $groupFulfilment): string
    {
        return $groupFulfilment === 'pickup_organizer' ? 'pickup' : 'delivery';
    }

    /** A short public code for share links: no look-alike characters. */
    public static function isCode(string $code): bool
    {
        return (bool) preg_match('/\A[a-hj-km-np-z2-9]{10}\z/', $code);
    }
}
