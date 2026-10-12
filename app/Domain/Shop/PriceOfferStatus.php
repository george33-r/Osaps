<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\StateMachine;

/**
 * inDrive-style price offer on a fabric: the buyer names a price, the vendor accepts, counters
 * or declines, and the buyer can answer a counter the same way. Only the side whose turn it is
 * may act; "system" handles expiry and turning an accepted price into an order line.
 */
final class PriceOfferStatus extends StateMachine
{
    public const AWAITING_VENDOR = 'awaiting_vendor';
    public const AWAITING_BUYER = 'awaiting_buyer';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';
    public const USED = 'used';

    /** States in which someone still has to respond. */
    public const LIVE = [self::AWAITING_VENDOR, self::AWAITING_BUYER];

    public const LABELS = [
        self::AWAITING_VENDOR => 'Waiting for the vendor',
        self::AWAITING_BUYER => 'Vendor countered',
        self::ACCEPTED => 'Price agreed',
        self::DECLINED => 'Declined',
        self::WITHDRAWN => 'Withdrawn',
        self::EXPIRED => 'Expired',
        self::USED => 'Bought',
    ];

    protected static function transitions(): array
    {
        return [
            self::AWAITING_VENDOR => [
                self::ACCEPTED => ['vendor'],
                self::AWAITING_BUYER => ['vendor'],
                self::DECLINED => ['vendor'],
                self::WITHDRAWN => ['buyer'],
                self::EXPIRED => ['system'],
            ],
            self::AWAITING_BUYER => [
                self::ACCEPTED => ['buyer'],
                self::AWAITING_VENDOR => ['buyer'],
                self::DECLINED => ['buyer'],
                self::EXPIRED => ['system'],
            ],
            self::ACCEPTED => [
                self::USED => ['system'],
                self::EXPIRED => ['system'],
            ],
            self::DECLINED => [],
            self::WITHDRAWN => [],
            self::EXPIRED => [],
            self::USED => [],
        ];
    }
}
