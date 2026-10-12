<?php

declare(strict_types=1);

namespace App\Domain\AsoEbi;

use App\Domain\Support\StateMachine;

/** An aṣọ-ẹbí group: open for guests to pay, then closed when the organiser (or the deadline) says so. */
final class AsoEbiStatus extends StateMachine
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    protected static function transitions(): array
    {
        return [
            self::OPEN => [self::CLOSED => ['organizer', 'system'], self::CANCELLED => ['organizer', 'admin']],
            self::CLOSED => [self::OPEN => ['organizer']],
            self::CANCELLED => [],
        ];
    }
}
