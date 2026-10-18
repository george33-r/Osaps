<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

class FabricResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => config('marketplace.fabric_categories.'.$this->category, $this->category),
            'description' => $this->description,
            'primary_color' => $this->primary_color,
            'colors' => $this->colors,
            'pattern' => $this->pattern,
            'material' => $this->material,
            'unit' => $this->unit,
            'price' => self::money($this->price_minor),
            'available_quantity' => $this->availableQuantity(),
            'stock_status' => $this->stockStatus(),
            'min_order_quantity' => $this->min_order_quantity,
            // Yards in a full piece for fabric usually sold by the piece (lace 5, Ankara 6), else null.
            'piece_yards' => $this->unit === 'yard' ? ((int) (config('marketplace.shop.piece_yards')[$this->category] ?? 0) ?: null) : null,
            // Null means no vendor photo yet; clients render an illustrated swatch, labelled as such.
            'image_url' => $this->relationLoaded('media') && ($first = $this->media->firstWhere('kind', 'photo'))
                ? $first->url()
                : ($this->image_path ? asset('storage/'.$this->image_path) : null),
            // Real photos and a short video of the actual bolt, uploaded by the vendor.
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => ['id' => $m->id, 'kind' => $m->kind, 'url' => $m->url(), 'mime' => $m->mime])->values()),
            'has_video' => $this->relationLoaded('media') ? $this->media->contains('kind', 'video') : false,
            'is_demo' => $this->is_demo,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'business_name' => $this->vendor->business_name,
                'slug' => $this->vendor->slug,
                'city' => $this->vendor->city,
                'market' => $this->vendor->market,
                'verified' => $this->vendor->verification_status === 'verified',
            ]),
            // Buy on OSAPS once payments are switched on; until then, contact the vendor.
            'purchase_mode' => app(\App\Payments\PaymentGateway::class)->enabled() ? 'checkout' : 'contact_vendor',
            'offer_floor' => app(\App\Domain\Shop\PriceOfferRules::class)->floor(\App\Domain\Money\Money::minor($this->price_minor))->toArray(),
            'offer_suggestions' => array_map(fn ($m) => $m->toArray(), app(\App\Domain\Shop\PriceOfferRules::class)->suggestions(\App\Domain\Money\Money::minor($this->price_minor))),
        ];
    }
}
