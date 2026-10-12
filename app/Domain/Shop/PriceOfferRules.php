<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money\Money;
use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;

/**
 * Rules for naming your price on fabric, inDrive-style.
 *
 *  - The buyer offers a price per unit below the listed price, but not below a floor
 *    (a share of the listed price) so vendors are not flooded with lowball offers.
 *  - The vendor accepts, declines, or counters with a price between the buyer's offer and the list price.
 *  - The buyer may accept a counter, decline it, or raise their own offer (never lower it), still below
 *    the vendor's counter. Each side has a limited number of counters.
 *  - Someone must respond within the response window; an agreed price is held for the buyer for a
 *    while, then lapses back to the list price.
 *  - The vendor can never push a price above the listing; the agreed price applies to at most the
 *    offered quantity.
 */
final class PriceOfferRules
{
    public function __construct(
        public readonly int $floorBps = 7_000,
        public readonly int $responseHours = 24,
        public readonly int $holdHours = 48,
        public readonly int $maxRoundsPerSide = 3,
        public readonly int $maxLivePerBuyer = 10,
        /** Suggested prices and floors are rounded to this many minor units (₦50). */
        public readonly int $roundingMinor = 5_000,
    ) {}

    public function floor(Money $list): Money
    {
        $raw = $list->percentBps($this->floorBps)->minor;

        return Money::minor($this->roundUp($raw), $list->currency);
    }

    /**
     * Quick-pick prices for the buyer (5%, 10% and 15% below the list price), rounded down to ₦50,
     * never below the floor, without duplicates.
     *
     * @return list<Money>
     */
    public function suggestions(Money $list): array
    {
        $floor = $this->floor($list)->minor;
        $out = [];
        foreach ([9_500, 9_000, 8_500] as $bps) {
            $p = max($floor, $this->roundDown($list->percentBps($bps)->minor));
            if ($p < $list->minor && ! in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return array_map(fn (int $m) => Money::minor($m, $list->currency), $out);
    }

    public function respondBy(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("+{$this->responseHours} hours");
    }

    public function holdUntil(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("+{$this->holdHours} hours");
    }

    /** @throws DomainRuleViolation */
    public function assertCanOpen(
        Money $list,
        Money $offer,
        int $quantity,
        int $minQuantity,
        int $available,
        bool $isOwnListing,
        bool $listingActive,
        int $buyerLiveOffers,
        bool $buyerHasLiveOfferOnFabric,
    ): void {
        if ($isOwnListing) {
            throw new DomainRuleViolation('You cannot make an offer on your own listing.', 'own_listing');
        }
        if (! $listingActive) {
            throw new DomainRuleViolation('This fabric is no longer listed.', 'fabric_unavailable');
        }
        $this->assertQuantity($quantity, $minQuantity, $available);
        if ($buyerHasLiveOfferOnFabric) {
            throw new DomainRuleViolation('You already have an offer on this fabric. Answer or withdraw it first.', 'duplicate_offer');
        }
        if ($buyerLiveOffers >= $this->maxLivePerBuyer) {
            throw new DomainRuleViolation("You have {$this->maxLivePerBuyer} offers waiting. Let some finish first.", 'buyer_live_offer_limit');
        }
        if ($offer->minor >= $list->minor) {
            throw new DomainRuleViolation('That is the listed price or more. Just add it to your cart.', 'at_or_above_list');
        }
        $floor = $this->floor($list);
        if ($floor->greaterThan($offer)) {
            throw new DomainRuleViolation('Offers start at '.$floor->format().' per unit for this fabric.', 'below_floor');
        }
    }

    /** @throws DomainRuleViolation */
    public function assertQuantity(int $quantity, int $minQuantity, int $available): void
    {
        if ($quantity < max(1, $minQuantity)) {
            throw new DomainRuleViolation('The minimum order for this fabric is '.max(1, $minQuantity).'.', 'below_min_quantity');
        }
        if ($quantity > $available) {
            throw new DomainRuleViolation($available > 0 ? "Only {$available} left in stock." : 'This fabric is out of stock.', 'insufficient_stock');
        }
    }

    /**
     * Vendor counter: strictly more than the buyer's latest price, no more than the listing.
     *
     * @throws DomainRuleViolation
     */
    public function assertVendorCounter(Money $list, Money $buyerPrice, Money $counter, int $vendorRoundsUsed): void
    {
        $this->assertRounds($vendorRoundsUsed);
        if (! $counter->greaterThan($buyerPrice)) {
            throw new DomainRuleViolation('Your counter must be above the buyer\'s offer. To agree to their price, accept it.', 'counter_too_low');
        }
        if ($counter->greaterThan($list)) {
            throw new DomainRuleViolation('Your counter cannot be above your listed price of '.$list->format().'.', 'counter_above_list');
        }
    }

    /**
     * Buyer counter: raises their own previous price, stays below the vendor's counter.
     *
     * @throws DomainRuleViolation
     */
    public function assertBuyerCounter(Money $list, Money $previousBuyerPrice, Money $vendorPrice, Money $counter, int $buyerRoundsUsed): void
    {
        $this->assertRounds($buyerRoundsUsed);
        if (! $counter->greaterThan($previousBuyerPrice)) {
            throw new DomainRuleViolation('Raise your offer above '.$previousBuyerPrice->format().', or accept the vendor\'s price.', 'counter_not_raised');
        }
        if (! $vendorPrice->greaterThan($counter)) {
            throw new DomainRuleViolation('That matches the vendor\'s price. Accept it instead.', 'counter_meets_vendor');
        }
        if (! $list->greaterThan($counter)) {
            throw new DomainRuleViolation('That is the listed price. Just add it to your cart.', 'at_or_above_list');
        }
    }

    /** @throws DomainRuleViolation */
    private function assertRounds(int $used): void
    {
        if ($used >= $this->maxRoundsPerSide) {
            throw new DomainRuleViolation("You have used all {$this->maxRoundsPerSide} counters. Accept or decline.", 'counter_limit');
        }
    }

    private function roundUp(int $minor): int
    {
        $r = $this->roundingMinor;

        return $r > 0 ? intdiv($minor + $r - 1, $r) * $r : $minor;
    }

    private function roundDown(int $minor): int
    {
        $r = $this->roundingMinor;

        return $r > 0 ? intdiv($minor, $r) * $r : $minor;
    }
}
