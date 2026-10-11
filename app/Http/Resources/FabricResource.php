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
            // Null means no vendor photo yet; clients render an illustrated swatch, labelled as such.
            'image_url' => $this->image_path ? asset('storage/'.$this->image_path) : null,
            'is_demo' => $this->is_demo,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'business_name' => $this->vendor->business_name,
                'slug' => $this->vendor->slug,
                'city' => $this->vendor->city,
                'market' => $this->vendor->market,
                'verified' => $this->vendor->verification_status === 'verified',
            ]),
            // Phase 1: discovery only. On-platform fabric checkout arrives with payments (Phase 4).
            'purchase_mode' => 'contact_vendor',
        ];
    }
}
