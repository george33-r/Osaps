<?php

namespace App\Services;

use App\Domain\Money\Money;
use App\Domain\Shop\PriceOfferRules;
use App\Domain\Shop\PriceOfferStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\Fabric;
use App\Models\PriceOffer;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * inDrive-style price offers on fabric. The only place a price offer changes. Each change runs in a
 * transaction with the offer row locked, so a buyer and vendor acting at the same moment cannot
 * both win: the second action sees the new state and is refused.
 */
class ShopOfferService
{
    public function __construct(private readonly PriceOfferRules $rules) {}

    /** Which side of this offer the user is on, or null if neither. */
    public function sideOf(User $user, PriceOffer $offer): ?string
    {
        if ($offer->buyer_id === $user->id) {
            return 'buyer';
        }
        $offer->loadMissing('vendor');

        return $offer->vendor?->user_id === $user->id ? 'vendor' : null;
    }

    public function open(User $buyer, Fabric $fabric, int $quantity, int $unitMinor, ?string $note): PriceOffer
    {
        $this->expireStale();

        return DB::transaction(function () use ($buyer, $fabric, $quantity, $unitMinor, $note) {
            $fabric = Fabric::query()->with('vendor')->lockForUpdate()->findOrFail($fabric->id);
            $live = PriceOffer::query()->where('buyer_id', $buyer->id)->whereIn('status', PriceOfferStatus::LIVE);

            $this->rules->assertCanOpen(
                list: Money::minor($fabric->price_minor),
                offer: Money::minor($unitMinor),
                quantity: $quantity,
                minQuantity: (int) $fabric->min_order_quantity,
                available: $fabric->availableQuantity(),
                isOwnListing: $fabric->vendor?->user_id === $buyer->id,
                listingActive: $fabric->status === 'active',
                buyerLiveOffers: (clone $live)->count(),
                buyerHasLiveOfferOnFabric: (clone $live)->where('fabric_id', $fabric->id)->exists(),
            );

            $offer = new PriceOffer(['quantity' => $quantity, 'note' => $note !== null ? mb_substr(trim($note), 0, 200) : null]);
            $offer->fabric_id = $fabric->id;
            $offer->buyer_id = $buyer->id;
            $offer->fabric_vendor_id = $fabric->fabric_vendor_id;
            $offer->list_unit_minor = $fabric->price_minor;
            $offer->unit_minor = $unitMinor;
            $offer->buyer_unit_minor = $unitMinor;
            $offer->status = PriceOfferStatus::AWAITING_VENDOR;
            $offer->respond_by = $this->rules->respondBy(new DateTimeImmutable);
            $offer->save();
            $offer->rounds()->create(['author_role' => 'buyer', 'author_id' => $buyer->id, 'action' => 'open', 'unit_minor' => $unitMinor]);

            return $offer;
        });
    }

    /** Accept the price currently on the table. $expectedUnitMinor is what the user saw. */
    public function accept(User $user, PriceOffer $offer, int $expectedUnitMinor): PriceOffer
    {
        return $this->mutate($user, $offer, function (PriceOffer $offer, string $side) use ($user, $expectedUnitMinor) {
            if ($offer->unit_minor !== $expectedUnitMinor) {
                throw new DomainRuleViolation('The price changed while you were looking. Check the new price.', 'offer_changed');
            }
            PriceOfferStatus::assert($offer->status, PriceOfferStatus::ACCEPTED, $side);
            $offer->status = PriceOfferStatus::ACCEPTED;
            $offer->accepted_at = now();
            $offer->hold_until = $this->rules->holdUntil(new DateTimeImmutable);
            $offer->respond_by = null;
            $offer->save();
            $offer->rounds()->create(['author_role' => $side, 'author_id' => $user->id, 'action' => 'accept', 'unit_minor' => $offer->unit_minor]);
        });
    }

    public function counter(User $user, PriceOffer $offer, int $unitMinor): PriceOffer
    {
        return $this->mutate($user, $offer, function (PriceOffer $offer, string $side) use ($user, $unitMinor) {
            $list = Money::minor($offer->list_unit_minor);
            $price = Money::minor($unitMinor);

            if ($side === 'vendor') {
                PriceOfferStatus::assert($offer->status, PriceOfferStatus::AWAITING_BUYER, 'vendor');
                $this->rules->assertVendorCounter($list, Money::minor($offer->buyer_unit_minor), $price, $offer->vendor_rounds);
                $offer->vendor_rounds++;
                $offer->vendor_unit_minor = $unitMinor;
                $offer->status = PriceOfferStatus::AWAITING_BUYER;
            } else {
                PriceOfferStatus::assert($offer->status, PriceOfferStatus::AWAITING_VENDOR, 'buyer');
                $this->rules->assertBuyerCounter($list, Money::minor($offer->buyer_unit_minor), Money::minor((int) $offer->vendor_unit_minor), $price, $offer->buyer_rounds);
                $offer->buyer_rounds++;
                $offer->buyer_unit_minor = $unitMinor;
                $offer->status = PriceOfferStatus::AWAITING_VENDOR;
            }
            $offer->unit_minor = $unitMinor;
            $offer->respond_by = $this->rules->respondBy(new DateTimeImmutable);
            $offer->save();
            $offer->rounds()->create(['author_role' => $side, 'author_id' => $user->id, 'action' => 'counter', 'unit_minor' => $unitMinor]);
        });
    }

    public function decline(User $user, PriceOffer $offer): PriceOffer
    {
        return $this->mutate($user, $offer, function (PriceOffer $offer, string $side) use ($user) {
            PriceOfferStatus::assert($offer->status, PriceOfferStatus::DECLINED, $side);
            $offer->status = PriceOfferStatus::DECLINED;
            $offer->respond_by = null;
            $offer->save();
            $offer->rounds()->create(['author_role' => $side, 'author_id' => $user->id, 'action' => 'decline', 'unit_minor' => null]);
        });
    }

    public function withdraw(User $user, PriceOffer $offer): PriceOffer
    {
        return $this->mutate($user, $offer, function (PriceOffer $offer, string $side) use ($user) {
            PriceOfferStatus::assert($offer->status, PriceOfferStatus::WITHDRAWN, $side);
            $offer->status = PriceOfferStatus::WITHDRAWN;
            $offer->respond_by = null;
            $offer->save();
            $offer->rounds()->create(['author_role' => $side, 'author_id' => $user->id, 'action' => 'withdraw', 'unit_minor' => null]);
        });
    }

    /** Lapse offers nobody answered in time, and agreed prices the buyer did not use. */
    public function expireStale(): int
    {
        $n = 0;
        $stale = PriceOffer::query()
            ->where(fn ($q) => $q->whereIn('status', PriceOfferStatus::LIVE)->where('respond_by', '<', now()))
            ->orWhere(fn ($q) => $q->where('status', PriceOfferStatus::ACCEPTED)->where('hold_until', '<', now()))
            ->limit(200)->pluck('id');

        foreach ($stale as $id) {
            DB::transaction(function () use ($id, &$n) {
                $offer = PriceOffer::query()->lockForUpdate()->find($id);
                if ($offer === null) {
                    return;
                }
                $due = $offer->isLive() ? $offer->respond_by?->isPast() : ($offer->status === PriceOfferStatus::ACCEPTED && $offer->hold_until?->isPast());
                if (! $due) {
                    return;
                }
                $offer->status = PriceOfferStatus::EXPIRED;
                $offer->save();
                $offer->rounds()->create(['author_role' => 'system', 'author_id' => null, 'action' => 'expire', 'unit_minor' => null]);
                $n++;
            });
        }

        return $n;
    }

    /** @param callable(PriceOffer, string): void $change */
    private function mutate(User $user, PriceOffer $offer, callable $change): PriceOffer
    {
        $this->expireStale();

        return DB::transaction(function () use ($user, $offer, $change) {
            $offer = PriceOffer::query()->with('vendor')->lockForUpdate()->findOrFail($offer->id);
            $side = $this->sideOf($user, $offer);
            if ($side === null) {
                throw new DomainRuleViolation('This offer is not yours.', 'forbidden');
            }
            $change($offer, $side);

            return $offer->fresh(['fabric.vendor', 'vendor', 'rounds']);
        });
    }
}
