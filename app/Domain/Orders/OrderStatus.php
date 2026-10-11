<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Support\StateMachine;

/**
 * Tailoring order lifecycle. Payment is a separate state machine (Phase 4) — an order
 * being "confirmed" does not mean money has moved.
 */
final class OrderStatus extends StateMachine
{
    public const CONFIRMED = 'confirmed';
    public const IN_PROGRESS = 'in_progress';
    public const FITTING = 'fitting';
    public const READY = 'ready';
    public const DELIVERED = 'delivered';
    public const COMPLETED = 'completed';
    public const DISPUTED = 'disputed';
    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::CONFIRMED => 'Order confirmed',
        self::IN_PROGRESS => 'Tailoring in progress',
        self::FITTING => 'Ready for fitting',
        self::READY => 'Ready for delivery',
        self::DELIVERED => 'Delivered',
        self::COMPLETED => 'Completed',
        self::DISPUTED => 'Under review',
        self::CANCELLED => 'Cancelled',
    ];

    /** The happy path, used to draw the customer timeline. */
    public const MILESTONES = [
        self::CONFIRMED, self::IN_PROGRESS, self::FITTING, self::READY, self::DELIVERED, self::COMPLETED,
    ];

    protected static function transitions(): array
    {
        return [
            self::CONFIRMED => [
                self::IN_PROGRESS => ['tailor'],
                self::CANCELLED => ['customer', 'tailor', 'admin'],
            ],
            self::IN_PROGRESS => [
                self::FITTING => ['tailor'],
                self::READY => ['tailor'],
                self::DISPUTED => ['customer'],
                self::CANCELLED => ['admin'],
            ],
            self::FITTING => [
                self::IN_PROGRESS => ['tailor'],
                self::READY => ['tailor'],
                self::DISPUTED => ['customer'],
            ],
            self::READY => [
                self::DELIVERED => ['tailor'],
                self::DISPUTED => ['customer'],
            ],
            self::DELIVERED => [
                self::COMPLETED => ['customer'],
                self::DISPUTED => ['customer'],
            ],
            // Disputes are resolved by a human admin with a recorded reason — never automatically.
            self::DISPUTED => [
                self::COMPLETED => ['admin'],
                self::IN_PROGRESS => ['admin'],
                self::CANCELLED => ['admin'],
            ],
            self::COMPLETED => [],
            self::CANCELLED => [],
        ];
    }

    /** Transitions that must carry a written reason. */
    public static function requiresReason(string $to): bool
    {
        return in_array($to, [self::CANCELLED, self::DISPUTED], true);
    }
}
