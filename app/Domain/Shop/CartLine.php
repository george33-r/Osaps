<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * One cart line as the server sees it, built from the database — never from prices the browser sent.
 * $offer* describe an agreed price offer the buyer attached to this line, if any.
 */
final class CartLine
{
    public function __construct(
        public readonly int $fabricId,
        public readonly int $vendorId,
        public readonly string $vendorName,
        public readonly string $fabricName,
        public readonly string $unit,
        public readonly int $quantity,
        public readonly int $listUnitMinor,
        public readonly int $available,
        public readonly int $minQuantity,
        public readonly bool $listed,
        public readonly ?int $offerId = null,
        public readonly ?int $offerUnitMinor = null,
        public readonly ?int $offerQuantity = null,
        /** True only if the offer is accepted, unexpired, unused, owned by this buyer and for this fabric. */
        public readonly bool $offerUsable = false,
        /** A price fixed by an aṣọ-ẹbí group deal. Like an offer, it can only lower the price. */
        public readonly ?int $fixedUnitMinor = null,
    ) {}
}
