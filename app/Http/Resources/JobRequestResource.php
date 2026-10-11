<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobRequestResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer?->id === $this->customer_id;

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'garment_category' => $this->garment_category,
            'garment_label' => config('marketplace.garment_categories.'.$this->garment_category, $this->garment_category),
            'description' => $this->description,
            'style_tags' => $this->style_tags ?? [],
            'mode' => $this->mode,
            'budget_min' => self::money($this->budget_min_minor),
            'budget_max' => self::money($this->budget_max_minor),
            'fabric_status' => $this->fabric_status,
            'fabric' => $this->whenLoaded('fabric', fn () => $this->fabric ? [
                'slug' => $this->fabric->slug, 'name' => $this->fabric->name,
            ] : null),
            'city' => $this->city,
            'needed_by' => $this->needed_by->toDateString(),
            'fitting_required' => $this->fitting_required,
            'status' => $this->isOpen() || $this->status !== 'open' ? $this->status : 'expired',
            'offers_close_at' => $this->offers_close_at->toIso8601String(),
            'is_owner' => $isOwner,
            // Data minimisation: tailors see a first name only — no email, phone or address before an order exists.
            'customer' => ['first_name' => Str::before((string) $this->customer?->name, ' ')],
            'offer_count' => $this->whenCounted('offers'),
            'live_offer_count' => $this->whenCounted('liveOffers'),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'order_reference' => $this->whenLoaded('order', fn () => $this->order?->reference),
            'match' => $this->when($this->hasExtra('match'), fn () => $this->extra('match')),
            'my_offer' => $this->when($this->hasExtra('my_offer'), fn () => $this->extra('my_offer')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
