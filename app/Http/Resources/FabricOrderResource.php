<?php

namespace App\Http\Resources;

use App\Domain\Shop\DisputeRules;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PayoutRules;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

/** Pass ['viewer' => 'customer'|'vendor'|'admin'] as extra. */
class FabricOrderResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $viewer = $this->extra('viewer') ?? 'customer';
        $role = $viewer;
        $disputeOpen = DisputeRules::isOpen($this->dispute_status);
        $next = array_values(array_filter(
            FabricOrderStatus::nextFor($this->status, $role),
            fn (string $to) => ($to !== FabricOrderStatus::CANCELLED || $role === 'admin')
                && ! ($disputeOpen && $to === FabricOrderStatus::COMPLETED)
                && (! in_array($to, [FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP], true) || $to === FabricOrderStatus::dispatchStep($this->fulfilment)),
        ));
        $paid = ! in_array($this->status, [FabricOrderStatus::PENDING_PAYMENT, FabricOrderStatus::CANCELLED], true);
        $seesMoney = $viewer !== 'vendor';
        $refunds = $this->relationLoaded('refunds') ? $this->refunds : collect();

        return [
            'reference' => $this->reference,
            'checkout_reference' => $this->checkout?->reference,
            'status' => $this->status,
            'status_label' => FabricOrderStatus::LABELS[$this->status] ?? $this->status,
            'fulfilment' => $this->fulfilment,
            'milestones' => array_map(fn ($s) => ['status' => $s, 'label' => FabricOrderStatus::LABELS[$s]], FabricOrderStatus::milestones($this->fulfilment)),
            // A vendor's "received" step needs the buyer's delivery code.
            'next' => array_map(fn ($s) => ['status' => $s, 'label' => FabricOrderStatus::LABELS[$s],
                'needs_code' => $role === 'vendor' && $s === FabricOrderStatus::DELIVERED], $next),
            // Shown only to the buyer, only while the fabric is on its way.
            'delivery_code' => $viewer === 'customer' && in_array($this->status, [FabricOrderStatus::PAID, FabricOrderStatus::PACKED, FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP], true)
                ? $this->delivery_code : null,
            'delivery_code_locked' => $role === 'vendor' && $this->delivery_code_attempts >= \App\Domain\Shop\DeliveryCode::MAX_ATTEMPTS,
            'delivered_by' => $this->delivered_by,
            'protection_days' => (int) config('marketplace.shop.auto_complete_days', 7),
            'dispute' => [
                'can_open' => $viewer === 'customer' && DisputeRules::canOpen($this->status, $this->dispute_status),
                'status' => $this->dispute_status,
                'reason' => $this->dispute_reason,
                'reason_label' => $this->dispute_reason ? (DisputeRules::REASONS[$this->dispute_reason] ?? $this->dispute_reason) : null,
                'details' => $viewer !== 'vendor' || $this->dispute_status ? $this->dispute_details : null,
                'opened_at' => $this->dispute_opened_at?->toIso8601String(),
                'resolved_at' => $this->dispute_resolved_at?->toIso8601String(),
                'resolution_note' => $this->dispute_resolution_note,
            ],
            'refunds' => $seesMoney ? $refunds->map(fn ($r) => [
                'id' => $r->id, 'amount' => self::money($r->amount_minor), 'status' => $r->status,
                'label' => \App\Domain\Payments\RefundRules::LABELS[$r->status] ?? $r->status,
                'failure' => $viewer === 'admin' ? $r->failure : null,
                'at' => $r->created_at?->toIso8601String(),
            ])->values() : [],
            'subtotal' => self::money($this->subtotal_minor),
            'delivery' => self::money($this->delivery_minor),
            'service_fee' => $seesMoney ? self::money($this->service_fee_minor) : null,
            'total' => $seesMoney ? self::money($this->total_minor) : null,
            // What the vendor is owed for the goods, after commission.
            'vendor_payout' => $viewer !== 'customer' ? self::money($this->subtotal_minor + $this->delivery_minor - $this->vendor_commission_minor) : null,
            // Buyer protection: the vendor is paid once the buyer has the fabric and the problem window has passed.
            'payout_status' => $viewer !== 'customer' ? $this->payout_status : null,
            'payout_label' => $viewer !== 'customer' ? (PayoutRules::LABELS[$this->payout_status] ?? $this->payout_status) : null,
            'aso_ebi' => $this->aso_ebi_group_id && $this->relationLoaded('group') && $this->group ? ['code' => $this->group->code, 'title' => $this->group->title] : null,
            'ship' => $this->checkout ? ['country' => $this->checkout->ship_country, 'arrival_date' => $this->checkout->arrival_date?->toDateString()] : null,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'business_name' => $this->vendor->business_name,
                'market' => $this->vendor->market,
                'city' => $this->vendor->city,
            ]),
            // The vendor needs the buyer's contact and address to deliver, and only once paid.
            'deliver_to' => $viewer !== 'customer' && $paid && $this->checkout ? (
                // An aṣọ-ẹbí collected by the organiser: the vendor deals with the organiser.
                $this->fulfilment === 'pickup' && $this->relationLoaded('group') && $this->group?->fulfilment === 'pickup_organizer'
                    ? ['name' => ($this->checkout->contact['name'] ?? 'Guest').' (organiser collects)', 'phone' => $this->group->organizer_phone, 'address' => null]
                    : ['name' => $this->checkout->contact['name'] ?? null, 'phone' => $this->checkout->contact['phone'] ?? null, 'address' => $this->checkout->address]
            ) : null,
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
