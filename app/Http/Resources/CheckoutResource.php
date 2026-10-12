<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

class CheckoutResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'payment_status' => $this->payment_status,
            'fulfilment' => $this->fulfilment,
            'subtotal' => self::money($this->subtotal_minor),
            'delivery' => self::money($this->delivery_minor),
            'service_fee' => self::money($this->service_fee_minor),
            'total' => self::money($this->total_minor),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            // Only returned right after creating the checkout, to send the buyer to Paystack.
            'authorization_url' => $this->when($this->hasExtra('authorization_url'), fn () => $this->extra('authorization_url')),
            'orders' => $this->whenLoaded('orders', fn () => $this->orders->map(
                fn ($o) => (new FabricOrderResource($o))->withExtra(['viewer' => 'customer'])->toArray($request)
            )->values()),
        ];
    }
}
