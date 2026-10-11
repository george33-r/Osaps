<?php

declare(strict_types=1);

namespace App\Domain\Negotiation;

use App\Domain\Support\StateMachine;

/**
 * An offer is a negotiation thread between one tailor and one job request.
 * Each price change is stored as an immutable revision; the status says whose turn it is.
 *
 * awaiting_customer — tailor's latest terms are on the table
 * awaiting_tailor   — customer countered; tailor must agree, counter or withdraw
 * accepted          — customer accepted the tailor's latest terms (price and scope lock)
 * declined          — customer turned the offer down
 * withdrawn         — tailor pulled out
 * expired           — nobody acted before the offer expired
 * not_selected      — customer accepted a different tailor's offer
 */
final class OfferStatus extends StateMachine
{
    public const AWAITING_CUSTOMER = 'awaiting_customer';
    public const AWAITING_TAILOR = 'awaiting_tailor';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';
    public const NOT_SELECTED = 'not_selected';

    public const LIVE = [self::AWAITING_CUSTOMER, self::AWAITING_TAILOR];

    protected static function transitions(): array
    {
        return [
            self::AWAITING_CUSTOMER => [
                self::ACCEPTED => ['customer'],
                self::DECLINED => ['customer'],
                self::AWAITING_TAILOR => ['customer'],
                self::WITHDRAWN => ['tailor'],
                self::EXPIRED => ['system'],
                self::NOT_SELECTED => ['system'],
            ],
            self::AWAITING_TAILOR => [
                self::AWAITING_CUSTOMER => ['tailor'],
                self::WITHDRAWN => ['tailor'],
                self::EXPIRED => ['system'],
                self::NOT_SELECTED => ['system'],
            ],
            self::ACCEPTED => [],
            self::DECLINED => [],
            self::WITHDRAWN => [],
            self::EXPIRED => [],
            self::NOT_SELECTED => [],
        ];
    }
}
