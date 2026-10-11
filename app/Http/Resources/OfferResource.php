<?php

namespace App\Http\Resources;

use App\Domain\Negotiation\OfferStatus;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

class OfferResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user?->id === $this->tailor_id ? 'tailor' : 'customer';
        $status = $this->effectiveStatus();
        $rounds = (int) config('marketplace.offers.max_counter_rounds_per_side');

        return [
            'id' => $this->id,
            'status' => $status,
            'amount' => self::money($this->amount_minor),
            'at_customer_budget' => $this->at_customer_budget,
            'delivery_days' => $this->delivery_days,
            'includes_fitting' => $this->includes_fitting,
            'alterations_included' => $this->alterations_included,
            'delivery_fee' => self::money($this->delivery_fee_minor),
            'message' => $this->message,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'rounds' => [
                'tailor_used' => $this->tailor_rounds,
                'customer_used' => $this->customer_rounds,
                'max_per_side' => $rounds,
            ],
            // What the viewer can do next — the server stays the authority; this only drives UI.
            'actions' => $status === OfferStatus::EXPIRED ? [] : OfferStatus::nextFor($this->status, $role),
            'tailor' => $this->whenLoaded('tailor', fn () => $this->tailor->tailorProfile
                ? (new TailorResource($this->tailor->tailorProfile))->resolve($request)
                : ['business_name' => $this->tailor->name]),
            'job_request' => $this->whenLoaded('jobRequest', fn () => [
                'id' => $this->jobRequest->public_id,
                'title' => $this->jobRequest->title,
                'status' => $this->jobRequest->status,
            ]),
            'revisions' => $this->whenLoaded('revisions', fn () => $this->revisions->map(fn ($r) => [
                'author_role' => $r->author_role,
                'amount' => self::money($r->amount_minor),
                'delivery_days' => $r->delivery_days,
                'note' => $r->note,
                'created_at' => $r->created_at?->toIso8601String(),
            ])),
            'comparison' => $this->when($this->hasExtra('comparison'), fn () => $this->extra('comparison')),
            'cost_breakdown' => $this->when($this->hasExtra('cost_breakdown'), fn () => $this->extra('cost_breakdown')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
