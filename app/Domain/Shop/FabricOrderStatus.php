<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\StateMachine;

/**
 * A fabric-only order from one vendor. Checkout creates one per vendor, all paid together.
 * Only a verified payment ("system") moves an order out of pending_payment. Cancelling a paid
 * order is an admin decision, recorded with a reason, because money has to be returned.
 */
final class FabricOrderStatus extends StateMachine
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAID = 'paid';
    public const PACKED = 'packed';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const DELIVERED = 'delivered';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::PENDING_PAYMENT => 'Awaiting payment',
        self::PAID => 'Paid',
        self::PACKED => 'Packed',
        self::OUT_FOR_DELIVERY => 'Out for delivery',
        self::READY_FOR_PICKUP => 'Ready for pickup',
        self::DELIVERED => 'Received',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    protected static function transitions(): array
    {
        return [
            self::PENDING_PAYMENT => [
                self::PAID => ['system'],
                self::CANCELLED => ['customer', 'system', 'admin'],
            ],
            self::PAID => [
                self::PACKED => ['vendor'],
                self::CANCELLED => ['admin'],
            ],
            self::PACKED => [
                self::OUT_FOR_DELIVERY => ['vendor'],
                self::READY_FOR_PICKUP => ['vendor'],
                self::CANCELLED => ['admin'],
            ],
            self::OUT_FOR_DELIVERY => [
                self::DELIVERED => ['vendor', 'customer'],
            ],
            self::READY_FOR_PICKUP => [
                self::DELIVERED => ['vendor', 'customer'],
            ],
            self::DELIVERED => [
                self::COMPLETED => ['customer', 'system'],
            ],
            self::COMPLETED => [],
            self::CANCELLED => [],
        ];
    }

    /** The next step after packing depends on how the buyer chose to receive the order. */
    public static function dispatchStep(string $fulfilment): string
    {
        return $fulfilment === 'pickup' ? self::READY_FOR_PICKUP : self::OUT_FOR_DELIVERY;
    }

    /** @return list<string> */
    public static function milestones(string $fulfilment): array
    {
        return [self::PAID, self::PACKED, self::dispatchStep($fulfilment), self::DELIVERED, self::COMPLETED];
    }

    /** Stock leaves the shelf only once payment is verified; before that it is merely reserved. */
    public static function holdsReservedStock(string $status): bool
    {
        return $status === self::PENDING_PAYMENT;
    }
}
