<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Money\Money;
use App\Domain\Pricing\FeeSchedule;
use App\Domain\Quotes\QuoteComparison;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobRequestRequest;
use App\Http\Resources\JobRequestResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\TailorResource;
use App\Models\JobRequest;
use App\Models\Offer;
use App\Services\MatchingService;
use App\Services\NegotiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class JobRequestController extends Controller
{
    public function __construct(
        private readonly NegotiationService $negotiation,
        private readonly MatchingService $matching,
        private readonly QuoteComparison $comparison,
        private readonly FeeSchedule $fees,
    ) {}

    /** The signed-in customer's own requests. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = $request->user()->jobRequests()
            ->withCount(['offers', 'liveOffers'])
            ->with(['order', 'customer', 'photos'])
            ->latest('id')
            ->paginate(20);

        return JobRequestResource::collection($requests);
    }

    public function store(StoreJobRequestRequest $request): JsonResponse
    {
        Gate::authorize('create', JobRequest::class);
        $job = $this->negotiation->createRequest($request->user(), $request->validated());
        $job->load(['fabric', 'customer', 'photos']);

        return (new JobRequestResource($job))->response()->setStatusCode(201);
    }

    /**
     * Owner view: every offer with rule-based comparison and full cost breakdown.
     * Tailor view: the brief, their own offer (if any) and how well they match.
     */
    public function show(Request $request, JobRequest $jobRequest): JsonResponse
    {
        Gate::authorize('view', $jobRequest);
        $user = $request->user();
        $jobRequest->load(['fabric', 'customer', 'order', 'photos']);
        $resource = new JobRequestResource($jobRequest);

        if ($user->id === $jobRequest->customer_id || $user->isAdmin()) {
            $offers = $jobRequest->offers()->with(['tailor.tailorProfile', 'revisions'])->orderBy('amount_minor')->get();

            $analysis = $this->comparison->analyse($offers
                ->filter(fn (Offer $o) => $o->isLive())
                ->map(fn (Offer $o) => [
                    'id' => $o->id,
                    'amount_minor' => $o->amount_minor,
                    'delivery_days' => $o->delivery_days,
                    'includes_fitting' => $o->includes_fitting,
                    'alterations_included' => $o->alterations_included,
                    'delivery_fee_minor' => $o->delivery_fee_minor,
                ])->values()->all());

            $data = $resource->resolve($request);
            $data['offers'] = $offers->map(function (Offer $o) use ($analysis, $request, $jobRequest) {
                $breakdown = $this->fees->customerBreakdown(
                    Money::minor($o->amount_minor),
                    Money::minor($o->delivery_fee_minor ?? 0),
                );
                $extra = [
                    'cost_breakdown' => [
                        'lines' => collect($breakdown['lines'])->map(fn ($l) => [
                            'key' => $l['key'], 'label' => $l['label'], 'amount' => $l['amount']->toArray(),
                        ])->all(),
                        'total' => $breakdown['total']->toArray(),
                        'within_budget' => $jobRequest->budget_max_minor === null
                            ? null
                            : $o->amount_minor <= $jobRequest->budget_max_minor,
                    ],
                ];
                if (isset($analysis[$o->id])) {
                    $extra['comparison'] = $analysis[$o->id] + ['source' => 'rules'];
                }

                return (new OfferResource($o))->withExtra($extra)->resolve($request);
            })->all();

            return response()->json(['data' => $data]);
        }

        // Tailor view
        $mine = $jobRequest->offers()->where('tailor_id', $user->id)->with('revisions')->first();
        $extra = ['my_offer' => $mine ? (new OfferResource($mine))->resolve($request) : null];
        if ($user->tailorProfile) {
            $extra['match'] = $this->matching->scoreProfileFor($user->tailorProfile, $jobRequest);
        }

        return response()->json(['data' => $resource->withExtra($extra)->resolve($request)]);
    }

    public function cancel(Request $request, JobRequest $jobRequest): JobRequestResource
    {
        Gate::authorize('cancel', $jobRequest);
        $job = $this->negotiation->cancelRequest($request->user(), $jobRequest);

        return new JobRequestResource($job->load(['fabric', 'customer', 'photos']));
    }

    /** Tailors best suited to this request, with plain-language reasons. Owner only. */
    public function matches(Request $request, JobRequest $jobRequest): JsonResponse
    {
        Gate::authorize('cancel', $jobRequest); // same rule: owner or admin

        $rows = $this->matching->rankFor($jobRequest)->map(
            fn ($row) => (new TailorResource($row['profile']))->withExtra(['match' => $row['match']])->resolve($request)
        );

        return response()->json(['data' => $rows, 'meta' => ['method' => 'rules', 'explainable' => true]]);
    }

    /** Tailor inbox: open requests, best matches first, flagged if the tailor already offered. */
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isTailor(), 403);
        $profile = $user->tailorProfile;

        $requests = JobRequest::query()->openForTailors()
            ->where('customer_id', '!=', $user->id)
            ->with(['customer', 'fabric', 'photos'])
            ->withCount('offers')
            ->latest('id')
            ->limit(100)
            ->get();

        $offered = Offer::query()->where('tailor_id', $user->id)
            ->whereIn('job_request_id', $requests->pluck('id'))
            ->pluck('status', 'job_request_id');

        $rows = $requests->map(function (JobRequest $job) use ($request, $profile, $offered) {
            $extra = ['my_offer' => $offered->has($job->id) ? ['status' => $offered[$job->id]] : null];
            if ($profile) {
                $extra['match'] = $this->matching->scoreProfileFor($profile, $job);
            }

            return (new JobRequestResource($job))->withExtra($extra)->resolve($request);
        })->sortByDesc(fn ($r) => $r['match']['score'] ?? 0)->values();

        return response()->json(['data' => $rows, 'meta' => ['has_profile' => $profile !== null]]);
    }
}
