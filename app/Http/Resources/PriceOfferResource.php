<?php

namespace App\Http\Resources;

use App\Domain\Money\Money;
use App\Domain\Shop\PriceOfferRules;
use App\Domain\Shop\PriceOfferStatus;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

/** A price offer as one side sees it. Pass ['side' => 'buyer'|'vendor'] as extra. */
class PriceOfferResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $rules = app(PriceOfferRules::class);
        $side = $this->extra('side') ?? 'buyer';
        $max = $rules->maxRoundsPerSide;
        $myTurn = ($side === 'vendor' && $this->status === PriceOfferStatus::AWAITING_VENDOR)
            || ($side === 'buyer' && $this->status === PriceOfferStatus::AWAITING_BUYER);
        $roundsLeft = $max - ($side === 'vendor' ? $this->vendor_rounds : $this->buyer_rounds);

        $actions = [];
        if ($myTurn) {
            $actions = ['accept', 'decline'];
            if ($roundsLeft > 0) {
                $actions[] = 'counter';
            }
        }
        if ($side === 'buyer' && $this->status === PriceOfferStatus::AWAITING_VENDOR) {
            $actions[] = 'withdraw';
        }
        if ($side === 'buyer' && $this->isUsable()) {
            $actions[] = 'add_to_cart';
        }

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => PriceOfferStatus::LABELS[$this->status] ?? $this->status,
            'your_side' => $side,
            'your_turn' => $myTurn,
            'actions' => $actions,
            'quantity' => $this->quantity,
            'list_unit' => self::money($this->list_unit_minor),
            'unit' => self::money($this->unit_minor),
            'buyer_unit' => self::money($this->buyer_unit_minor),
            'vendor_unit' => self::money($this->vendor_unit_minor),
            'floor_unit' => $rules->floor(Money::minor($this->list_unit_minor))->toArray(),
            'total' => self::money($this->unit_minor * $this->quantity),
            'saving' => self::money(max(0, $this->list_unit_minor - $this->unit_minor) * $this->quantity),
            'rounds_left' => max(0, $roundsLeft),
            'note' => $this->note,
            'respond_by' => $this->respond_by?->toIso8601String(),
            'hold_until' => $this->hold_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'fabric' => $this->whenLoaded('fabric', fn () => [
                'id' => $this->fabric->id,
                'slug' => $this->fabric->slug,
                'name' => $this->fabric->name,
                'unit' => $this->fabric->unit,
                'category_label' => config('marketplace.fabric_categories.'.$this->fabric->category, $this->fabric->category),
                'colors' => $this->fabric->colors,
                'pattern' => $this->fabric->pattern,
                'image_url' => $this->fabric->image_path ? asset('storage/'.$this->fabric->image_path) : null,
                'vendor' => $this->fabric->relationLoaded('vendor') ? [
                    'business_name' => $this->fabric->vendor->business_name,
                    'market' => $this->fabric->vendor->market,
                    'city' => $this->fabric->vendor->city,
                ] : null,
            ]),
            // Vendors see the buyer's first name only.
            'buyer_name' => $side === 'vendor' ? strtok((string) $this->buyer?->name, ' ') : null,
            'history' => $this->whenLoaded('rounds', fn () => $this->rounds->map(fn ($r) => [
                'by' => $r->author_role,
                'action' => $r->action,
                'unit' => self::money($r->unit_minor),
                'at' => $r->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
