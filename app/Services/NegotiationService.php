<?php

namespace App\Services;

use App\Domain\Money\Money;
use App\Domain\Negotiation\JobRequestStatus;
use App\Domain\Negotiation\OfferRules;
use App\Domain\Negotiation\OfferStatus;
use App\Domain\Orders\OrderStatus;
use App\Domain\Pricing\FeeSchedule;
use App\Domain\Support\DomainRuleViolation;
use App\Models\Fabric;
use App\Models\JobRequest;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place job requests, offers and their resulting orders change state.
 * Every mutation runs in a transaction with the parent request row locked.
 */
class NegotiationService
{
    public function __construct(
        private readonly OfferRules $rules,
        private readonly FeeSchedule $fees,
    ) {}

    /** @param array<string,mixed> $data validated input */
    public function createRequest(User $customer, array $data): JobRequest
    {
        $openCount = $customer->jobRequests()->where('status', JobRequestStatus::OPEN)->count();
        $max = (int) config('marketplace.requests.max_open_per_customer');
        if ($openCount >= $max) {
            throw new DomainRuleViolation("You already have {$max} open requests. Close one before posting another.", 'customer_open_request_limit');
        }

        if (! empty($data['fabric_id']) && ! Fabric::query()->listed()->whereKey($data['fabric_id'])->exists()) {
            throw new DomainRuleViolation('That fabric is no longer available.', 'fabric_unavailable');
        }

        $request = new JobRequest($data);
        $request->customer_id = $customer->id;
        $request->status = JobRequestStatus::OPEN;
        if ($request->mode === OfferRules::MODE_BUDGET) {
            $request->budget_min_minor = null; // budget mode names one price
        }
        $closes = now()->addDays((int) config('marketplace.requests.open_days'));
        $request->offers_close_at = $closes->min($request->needed_by->copy()->endOfDay());
        $request->save();

        return $request;
    }

    public function cancelRequest(User $customer, JobRequest $request): JobRequest
    {
        return DB::transaction(function () use ($customer, $request) {
            $request = JobRequest::query()->lockForUpdate()->findOrFail($request->id);
            JobRequestStatus::assert($request->status, JobRequestStatus::CANCELLED, $customer->isAdmin() ? 'admin' : 'customer');
            $request->status = JobRequestStatus::CANCELLED;
            $request->save();
            $this->closeLiveOffers($request, OfferStatus::NOT_SELECTED);

            return $request;
        });
    }

    /** @param array<string,mixed> $data validated input */
    public function submitOffer(User $tailor, JobRequest $request, array $data): Offer
    {
        $profile = $tailor->tailorProfile;
        if ($profile === null) {
            throw new DomainRuleViolation('Complete your tailor profile before making offers.', 'profile_required');
        }
        if (! $profile->accepting_work) {
            throw new DomainRuleViolation('Turn on "accepting work" in your profile to make offers.', 'not_accepting_work');
        }

        return DB::transaction(function () use ($tailor, $request, $data) {
            $request = JobRequest::query()->lockForUpdate()->findOrFail($request->id);

            $this->rules->assertCanOpenOffer(
                requestStatus: $request->isOpen() ? $request->status : JobRequestStatus::EXPIRED,
                tailorAlreadyHasOffer: $request->offers()->where('tailor_id', $tailor->id)->exists(),
                offersOnRequest: $request->offers()->count(),
                tailorLiveOffers: Offer::query()->where('tailor_id', $tailor->id)
                    ->whereIn('status', OfferStatus::LIVE)->where('expires_at', '>', now())->count(),
                isOwnRequest: $request->customer_id === $tailor->id,
            );

            $amount = Money::minor((int) $data['amount_minor']);
            $budget = $request->budget_max_minor !== null ? Money::minor($request->budget_max_minor) : null;
            $this->rules->assertExplanation($request->mode, $amount, $budget, $data['message'] ?? null);

            $offer = new Offer($data);
            $offer->job_request_id = $request->id;
            $offer->tailor_id = $tailor->id;
            $offer->status = OfferStatus::AWAITING_CUSTOMER;
            $offer->at_customer_budget = $budget !== null && $request->mode === OfferRules::MODE_BUDGET && $amount->equals($budget);
            $offer->expires_at = $this->rules->expiresAt(new DateTimeImmutable);
            $offer->save();

            $offer->revisions()->create([
                'author_role' => 'tailor', 'author_id' => $tailor->id,
                'amount_minor' => $amount->minor, 'delivery_days' => $offer->delivery_days, 'note' => $offer->message,
            ]);

            return $offer;
        });
    }

    /** Customer proposes a different price. */
    public function customerCounter(User $customer, Offer $offer, int $amountMinor, ?string $note): Offer
    {
        return $this->mutateOffer($offer, function (Offer $offer, JobRequest $request) use ($customer, $amountMinor, $note) {
            $this->assertLive($offer);
            $this->rules->assertCanCounter($offer->customer_rounds);
            OfferStatus::assert($offer->status, OfferStatus::AWAITING_TAILOR, 'customer');

            $offer->status = OfferStatus::AWAITING_TAILOR;
            $offer->customer_rounds++;
            $offer->amount_minor = Money::minor($amountMinor)->minor;
            $offer->expires_at = $this->rules->expiresAt(new DateTimeImmutable);
            $offer->save();
            $offer->revisions()->create([
                'author_role' => 'customer', 'author_id' => $customer->id,
                'amount_minor' => $amountMinor, 'delivery_days' => $offer->delivery_days, 'note' => $note,
            ]);

            return $offer;
        });
    }

    /**
     * Tailor revises terms: either agrees to the customer's counter (same amount, no round used)
     * or proposes a new price (uses one of the tailor's counter rounds).
     */
    public function tailorRevise(User $tailor, Offer $offer, int $amountMinor, ?int $deliveryDays, ?string $note): Offer
    {
        return $this->mutateOffer($offer, function (Offer $offer, JobRequest $request) use ($tailor, $amountMinor, $deliveryDays, $note) {
            $this->assertLive($offer);
            $agreeing = $offer->status === OfferStatus::AWAITING_TAILOR && $amountMinor === $offer->amount_minor;

            if (! $agreeing) {
                $this->rules->assertCanCounter($offer->tailor_rounds);
                $budget = $request->budget_max_minor !== null ? Money::minor($request->budget_max_minor) : null;
                $this->rules->assertExplanation($request->mode, Money::minor($amountMinor), $budget, $note);
                $offer->tailor_rounds++;
            }

            if ($offer->status === OfferStatus::AWAITING_TAILOR) {
                OfferStatus::assert($offer->status, OfferStatus::AWAITING_CUSTOMER, 'tailor');
            }

            $offer->status = OfferStatus::AWAITING_CUSTOMER;
            $offer->amount_minor = $amountMinor;
            $offer->delivery_days = $deliveryDays ?? $offer->delivery_days;
            $offer->at_customer_budget = $request->mode === OfferRules::MODE_BUDGET && $amountMinor === $request->budget_max_minor;
            $offer->expires_at = $this->rules->expiresAt(new DateTimeImmutable);
            $offer->save();
            $offer->revisions()->create([
                'author_role' => 'tailor', 'author_id' => $tailor->id,
                'amount_minor' => $amountMinor, 'delivery_days' => $offer->delivery_days,
                'note' => $note ?? ($agreeing ? 'Agreed to your price.' : null),
            ]);

            return $offer;
        });
    }

    public function withdraw(User $tailor, Offer $offer): Offer
    {
        return $this->mutateOffer($offer, function (Offer $offer) {
            OfferStatus::assert($offer->status, OfferStatus::WITHDRAWN, 'tailor');
            $offer->status = OfferStatus::WITHDRAWN;
            $offer->save();

            return $offer;
        });
    }

    public function decline(User $customer, Offer $offer): Offer
    {
        return $this->mutateOffer($offer, function (Offer $offer) {
            OfferStatus::assert($offer->status, OfferStatus::DECLINED, 'customer');
            $offer->status = OfferStatus::DECLINED;
            $offer->save();

            return $offer;
        });
    }

    /**
     * Customer accepts a tailor's current terms. Locks price and scope into an order.
     * Idempotent: retrying the same acceptance returns the same order.
     */
    public function accept(User $customer, Offer $offer): Order
    {
        return DB::transaction(function () use ($customer, $offer) {
            $request = JobRequest::query()->lockForUpdate()->findOrFail($offer->job_request_id);
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($offer->status === OfferStatus::ACCEPTED && $request->awarded_offer_id === $offer->id) {
                return Order::query()->where('offer_id', $offer->id)->firstOrFail();
            }
            if (! $request->isOpen()) {
                throw new DomainRuleViolation('This request is no longer open.', 'request_closed');
            }
            $this->assertLive($offer);
            OfferStatus::assert($offer->status, OfferStatus::ACCEPTED, 'customer');
            JobRequestStatus::assert($request->status, JobRequestStatus::AWARDED, 'customer');

            $offer->status = OfferStatus::ACCEPTED;
            $offer->save();

            $request->status = JobRequestStatus::AWARDED;
            $request->awarded_offer_id = $offer->id;
            $request->save();

            $this->closeLiveOffers($request, OfferStatus::NOT_SELECTED, except: $offer->id);

            $tailoring = Money::minor($offer->amount_minor);
            $delivery = Money::minor($offer->delivery_fee_minor ?? 0);
            $breakdown = $this->fees->customerBreakdown($tailoring, $delivery);
            $serviceFee = collect($breakdown['lines'])->firstWhere('key', 'service_fee')['amount'];
            $payout = $this->fees->tailorPayout($tailoring);

            $order = new Order;
            $order->forceFill([
                'reference' => $this->newReference(),
                'job_request_id' => $request->id,
                'offer_id' => $offer->id,
                'customer_id' => $request->customer_id,
                'tailor_id' => $offer->tailor_id,
                'status' => OrderStatus::CONFIRMED,
                'spec_snapshot' => $this->snapshot($request, $offer),
                'spec_version' => 1,
                'tailoring_minor' => $tailoring->minor,
                'delivery_fee_minor' => $delivery->minor,
                'service_fee_minor' => $serviceFee->minor,
                'total_minor' => $breakdown['total']->minor,
                'tailor_commission_minor' => $payout['commission']->minor,
                'payment_status' => 'unpaid',
                'due_on' => $offer->delivery_days
                    ? now()->addDays($offer->delivery_days)->toDateString()
                    : $request->needed_by->toDateString(),
            ])->save();

            $order->events()->create([
                'actor_id' => $customer->id, 'actor_role' => 'customer',
                'from_status' => null, 'to_status' => OrderStatus::CONFIRMED,
                'note' => 'Offer accepted. Price and scope locked.',
            ]);

            return $order;
        });
    }

    /** Sweep for the scheduler: expire offers and requests whose windows have passed. */
    public function expireStale(): array
    {
        $offers = 0;
        Offer::query()->whereIn('status', OfferStatus::LIVE)->where('expires_at', '<=', now())
            ->chunkById(200, function ($chunk) use (&$offers) {
                foreach ($chunk as $offer) {
                    OfferStatus::assert($offer->status, OfferStatus::EXPIRED, 'system');
                    $offer->status = OfferStatus::EXPIRED;
                    $offer->save();
                    $offers++;
                }
            });

        $requests = 0;
        JobRequest::query()->where('status', JobRequestStatus::OPEN)->where('offers_close_at', '<=', now())
            ->chunkById(200, function ($chunk) use (&$requests) {
                foreach ($chunk as $request) {
                    DB::transaction(function () use ($request) {
                        $request->status = JobRequestStatus::EXPIRED;
                        $request->save();
                        $this->closeLiveOffers($request, OfferStatus::EXPIRED);
                    });
                    $requests++;
                }
            });

        return ['offers' => $offers, 'requests' => $requests];
    }

    private function mutateOffer(Offer $offer, callable $fn): Offer
    {
        return DB::transaction(function () use ($offer, $fn) {
            $request = JobRequest::query()->lockForUpdate()->findOrFail($offer->job_request_id);
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($request->status !== JobRequestStatus::OPEN && in_array($offer->status, OfferStatus::LIVE, true)) {
                throw new DomainRuleViolation('This request is no longer open.', 'request_closed');
            }

            return $fn($offer, $request);
        });
    }

    private function assertLive(Offer $offer): void
    {
        if (in_array($offer->status, OfferStatus::LIVE, true) && ! $offer->expires_at->isFuture()) {
            throw new DomainRuleViolation('This offer has expired.', 'offer_expired');
        }
    }

    private function closeLiveOffers(JobRequest $request, string $to, ?int $except = null): void
    {
        $request->offers()->whereIn('status', OfferStatus::LIVE)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->get()
            ->each(function (Offer $o) use ($to) {
                OfferStatus::assert($o->status, $to, 'system');
                $o->status = $to;
                $o->save();
            });
    }

    /** @return array<string,mixed> */
    private function snapshot(JobRequest $request, Offer $offer): array
    {
        $request->loadMissing('fabric');

        return [
            'title' => $request->title,
            'garment_category' => $request->garment_category,
            'description' => $request->description,
            'style_tags' => $request->style_tags ?? [],
            'fabric_status' => $request->fabric_status,
            'fabric' => $request->fabric ? ['id' => $request->fabric->id, 'name' => $request->fabric->name] : null,
            'city' => $request->city,
            'needed_by' => $request->needed_by->toDateString(),
            'fitting_required' => $request->fitting_required,
            'terms' => [
                'amount_minor' => $offer->amount_minor,
                'delivery_days' => $offer->delivery_days,
                'includes_fitting' => $offer->includes_fitting,
                'alterations_included' => $offer->alterations_included,
                'delivery_fee_minor' => $offer->delivery_fee_minor,
                'message' => $offer->message,
                'negotiation_rounds' => $offer->tailor_rounds + $offer->customer_rounds,
            ],
            'accepted_at' => now()->toIso8601String(),
        ];
    }

    private function newReference(): string
    {
        do {
            $ref = 'OSP-'.strtoupper(Str::random(8));
        } while (Order::query()->where('reference', $ref)->exists());

        return $ref;
    }
}
