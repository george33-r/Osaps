<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Verified tailors and shops are checked again every six weeks. Their badges are hidden while a
 * check is overdue; their listings and bookings are not affected.
 */
final class Recheck
{
    public const INTERVAL_DAYS = 42;
    public const WARNING_DAYS = 14;

    public const OK = 'ok';
    public const WARNING = 'warning';
    public const OVERDUE = 'overdue';

    public static function dueAfterCheck(DateTimeInterface $at): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$at->getTimestamp()))->modify('+'.self::INTERVAL_DAYS.' days');
    }

    public static function state(?DateTimeInterface $due, DateTimeInterface $now): string
    {
        if ($due === null) {
            return self::OK;
        }
        if ($now >= $due) {
            return self::OVERDUE;
        }
        $warnFrom = (new DateTimeImmutable('@'.$due->getTimestamp()))->modify('-'.self::WARNING_DAYS.' days');

        return $now >= $warnFrom ? self::WARNING : self::OK;
    }

    /**
     * A check is complete when every required kind has an approval made in the current cycle,
     * that is, on or after the start of the six weeks that ends at $due.
     *
     * @param  array<string, DateTimeInterface|null>  $approvedAtByKind  latest approval time per kind
     * @param  list<string>  $required
     */
    public static function cycleComplete(array $approvedAtByKind, array $required, DateTimeInterface $due): bool
    {
        $start = (new DateTimeImmutable('@'.$due->getTimestamp()))->modify('-'.self::INTERVAL_DAYS.' days');
        foreach ($required as $kind) {
            $at = $approvedAtByKind[$kind] ?? null;
            if ($at === null || $at < $start) {
                return false;
            }
        }

        return true;
    }
}
