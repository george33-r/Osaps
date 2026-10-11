<?php

namespace App\Http\Resources;

use App\Domain\Orders\OrderStatus;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

class OrderResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $role = $this->roleOf($request->user());
        $isTailor = $role === 'tailor';

        return [
            'reference' => $this->reference,
            // The offer thread stays open after acceptance, so the parties keep one conversation.
            'offer_id' => $this->offer_id,
            'status' => $this->status,
            'status_label' => OrderStatus::LABELS[$this->status] ?? $this->status,
            'viewer_role' => $role,
            'spec' => $this->spec_snapshot,
            'spec_version' => $this->spec_version,
            'costs' => [
                'tailoring' => self::money($this->tailoring_minor),
                'delivery' => self::money($this->delivery_fee_minor),
                'service_fee' => self::money($this->service_fee_minor),
                'total' => self::money($this->total_minor),
            ],
            // Commission is shown to the tailor (their payout) and admins, not to customers.
            'tailor_payout' => $this->when($isTailor || $role === 'admin', fn () => [
                'gross' => self::money($this->tailoring_minor),
                'commission' => self::money($this->tailor_commission_minor),
                'net' => self::money($this->tailoring_minor - $this->tailor_commission_minor),
            ]),
            'payment_status' => $this->payment_status,
            'due_on' => $this->due_on?->toDateString(),
            'milestones' => collect(OrderStatus::MILESTONES)->map(fn ($s) => [
                'status' => $s, 'label' => OrderStatus::LABELS[$s],
            ]),
            'actions' => $role ? collect(OrderStatus::nextFor($this->status, $role))
                ->reject(fn ($s) => $s === OrderStatus::DISPUTED && ! config('marketplace.disputes_enabled'))
                ->map(fn ($s) => [
                'status' => $s,
                'label' => OrderStatus::LABELS[$s],
                'requires_reason' => OrderStatus::requiresReason($s),
            ])->values() : [],
            'tailor' => $this->whenLoaded('tailor', fn () => $this->tailor->tailorProfile
                ? ['business_name' => $this->tailor->tailorProfile->business_name, 'slug' => $this->tailor->tailorProfile->slug]
                : ['business_name' => $this->tailor->name]),
            // Contact details are shared between the two parties only once an order exists.
            'customer' => $this->whenLoaded('customer', fn () => $isTailor || $role === 'admin'
                ? ['name' => $this->customer->name, 'phone' => $this->customer->phone, 'city' => $this->customer->city]
                : ['name' => $this->customer->name]),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'label' => OrderStatus::LABELS[$e->to_status] ?? $e->to_status,
                'actor_role' => $e->actor_role,
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
