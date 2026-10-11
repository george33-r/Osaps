<?php

declare(strict_types=1);

namespace App\Domain\Negotiation;

use App\Domain\Support\StateMachine;

/**
 * Lifecycle of a customer's tailoring request.
 * open      — visible to eligible tailors, accepting offers
 * awarded   — customer accepted an offer; an order now exists
 * cancelled — customer withdrew the request
 * expired   — no offer was accepted before the request deadline
 */
final class JobRequestStatus extends StateMachine
{
    public const OPEN = 'open';
    public const AWARDED = 'awarded';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    protected static function transitions(): array
    {
        return [
            self::OPEN => [
                self::AWARDED => ['customer'],
                self::CANCELLED => ['customer', 'admin'],
                self::EXPIRED => ['system'],
            ],
            self::AWARDED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        ];
    }
}
