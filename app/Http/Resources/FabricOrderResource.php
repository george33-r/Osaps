<?php

namespace App\Http\Resources;

use App\Domain\Shop\FabricOrderStatus;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

/** Pass ['viewer' => 'customer'|'vendor'] as extra. */
class FabricOrderResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $viewer = $this->extra('viewer') ?? 'customer';
        $role = $viewer === 'vendor' ? 'vendor' : 'customer';
        $next = array_values(array_filter(
            FabricOrderStatus::nextFor($this->status, $role),
            fn (string $to) => $to !== FabricOrderStatus::CANCELLED
                && (! in_array($to, [FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP], true) || $to === FabricOrderStatus::dispatchStep($this->fulfilment)),
        ));
        $paid = ! in_array($this->status, [FabricOrderStatus::PENDING_PAYMENT, FabricOrderStatus::CANCELLED], true);

        return [
            'reference' => $this->reference,
            'checkout_reference' => $this->checkout?->reference,
            'status' => $this->status,
            'status_label' => FabricOrderStatus::LABELS[$this->status] ?? $this->status,
            'fulfilment' => $this->fulfilment,
            'milestones' => array_map(fn ($s) => ['status' => $s, 'label' => FabricOrderStatus::LABELS[$s]], FabricOrderStatus::milestones($this->fulfilment)),
            'next' => array_map(fn ($s) => ['status' => $s, 'label' => FabricOrderStatus::LABELS[$s]], $next),
            'subtotal' => self::money($this->subtotal_minor),
            'delivery' => self::money($this->delivery_minor),
            'service_fee' => $viewer === 'customer' ? self::money($this->service_fee_minor) : null,
            'total' => $viewer === 'customer' ? self::money($this->total_minor) : null,
            // What the vendor is owed for the goods, after commission.
            'vendor_payout' => $viewer === 'vendor' ? self::money($this->subtotal_minor + $this->delivery_minor - $this->vendor_commission_minor) : null,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'business_name' => $this->vendor->business_name,
                'market' => $this->vendor->market,
                'city' => $this->vendor->city,
            ]),
            // The vendor needs the buyer's contact and address to deliver, and only once paid.
            'deliver_to' => $viewer === 'vendor' && $paid && $this->checkout ? [
                'name' => $this->checkout->contact['name'] ?? null,
                'phone' => $this->checkout->contact['phone'] ?? null,
                'address' => $this->checkout->address,
            ] : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'fabric_id' => $i->fabric_id,
                'slug' => $i->fabric?->slug,
                'name' => $i->name,
                'unit' => $i->unit,
                'quantity' => $i->quantity,
                'unit_price' => self::money($i->unit_minor),
                'list_unit_price' => self::money($i->list_unit_minor),
                'line_total' => self::money($i->line_total_minor),
                'negotiated' => $i->price_offer_id !== null,
                'colors' => $i->fabric?->colors,
                'pattern' => $i->fabric?->pattern,
            ])->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'to' => $e->to_status,
                'label' => FabricOrderStatus::LABELS[$e->to_status] ?? $e->to_status,
                'by' => $e->actor_role,
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
