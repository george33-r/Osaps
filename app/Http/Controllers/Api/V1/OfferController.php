<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CounterOfferRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Resources\OfferResource;
use App\Http\Resources\OrderResource;
use App\Models\JobRequest;
use App\Models\Offer;
use App\Services\NegotiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OfferController extends Controller
{
    public function __construct(private readonly NegotiationService $negotiation) {}

    /** A tailor's own offers across all requests. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->isTailor(), 403);

        return OfferResource::collection(
            $request->user()->offers()->with(['jobRequest', 'revisions'])->latest('updated_at')->paginate(30)
        );
    }

    public function store(StoreOfferRequest $request, JobRequest $jobRequest): JsonResponse
    {
        Gate::authorize('makeOffer', $jobRequest);
        $offer = $this->negotiation->submitOffer($request->user(), $jobRequest, $request->validated());

        return (new OfferResource($offer->load('revisions')))->response()->setStatusCode(201);
    }

    /** Customer counteroffer. */
    public function counter(CounterOfferRequest $request, Offer $offer): OfferResource
    {
        Gate::authorize('respondAsCustomer', $offer);
        $offer = $this->negotiation->customerCounter($request->user(), $offer, (int) $request->input('amount_minor'), $request->input('note'));

        return new OfferResource($offer->load('revisions'));
    }

    /** Tailor revises terms or agrees to the customer's counter. */
    public function revise(CounterOfferRequest $request, Offer $offer): OfferResource
    {
        Gate::authorize('respondAsTailor', $offer);
        $offer = $this->negotiation->tailorRevise(
            $request->user(), $offer,
            (int) $request->input('amount_minor'),
            $request->filled('delivery_days') ? (int) $request->input('delivery_days') : null,
            $request->input('note'),
        );

        return new OfferResource($offer->load('revisions'));
    }

    public function withdraw(Request $request, Offer $offer): OfferResource
    {
        Gate::authorize('respondAsTailor', $offer);

        return new OfferResource($this->negotiation->withdraw($request->user(), $offer));
    }

    public function decline(Request $request, Offer $offer): OfferResource
    {
        Gate::authorize('respondAsCustomer', $offer);

        return new OfferResource($this->negotiation->decline($request->user(), $offer));
    }

    /** Idempotent: a retried acceptance returns the same order with 200. */
    public function accept(Request $request, Offer $offer): JsonResponse
    {
        Gate::authorize('respondAsCustomer', $offer);
        $order = $this->negotiation->accept($request->user(), $offer);
        $order->load(['tailor.tailorProfile', 'customer', 'events']);

        return (new OrderResource($order))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }
}
