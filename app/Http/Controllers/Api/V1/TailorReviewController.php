<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TailorProfile;
use App\Models\TailorReview;
use App\Models\TailorReviewAppeal;
use App\Models\TailorReviewPhoto;
use App\Models\TailorReviewRevision;
use App\Models\TailorReviewReport;
use App\Services\PrivatePhotoService;
use App\Services\TailorReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;

class TailorReviewController extends Controller
{
    public function __construct(
        private readonly TailorReviewService $reviews,
        private readonly PrivatePhotoService $photos,
    ) {}

    // ---- Customer ------------------------------------------------------------------------

    /** The customer's review of this order, and whether a review is still allowed. */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);
        $review = TailorReview::query()->where('order_id', $order->id)->with(['revisions', 'photos', 'response', 'customer'])->first();
        $closes = $order->completed_at?->copy()->addDays(TailorReviewService::REVIEW_WINDOW_DAYS);

        return response()->json(['data' => [
            'review' => $review ? $this->present($review, 'owner') : null,
            'eligible' => $review === null && $order->status === \App\Domain\Orders\OrderStatus::COMPLETED && $order->payment_status === 'paid' && $closes !== null && now()->lessThanOrEqualTo($closes),
            'window_closes_at' => $closes?->toIso8601String(),
        ]]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate($this->contentRules() + [
            'photos' => ['nullable', 'array', 'max:'.TailorReviewService::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);
        $review = $this->reviews->submit($request->user(), $order, $data, $request->file('photos', []));

        return response()->json(['data' => $this->present($review->load(['revisions', 'photos', 'customer']), 'owner')], 201);
    }

    public function correct(Request $request, TailorReview $review): JsonResponse
    {
        $data = $request->validate($this->contentRules());
        $review = $this->reviews->correct($request->user(), $review, $data);

        return response()->json(['data' => $this->present($review->load(['revisions', 'photos', 'response', 'customer']), 'owner')]);
    }

    public function report(Request $request, TailorReview $review): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(TailorReviewService::REPORT_REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $report = $this->reviews->report($request->user(), $review, $data['reason'], $data['details'] ?? null);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]], 201);
    }

    public function appeal(Request $request, TailorReview $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $appeal = $this->reviews->appeal($request->user(), $review, $data['reason']);

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]], 201);
    }

    // ---- Tailor --------------------------------------------------------------------------

    /** Published reviews of the signed-in tailor, with their own reply. Hidden reviews are not shown to the tailor. */
    public function mine(Request $request): JsonResponse
    {
        $profile = $request->user()->tailorProfile;
        if ($profile === null) {
            return response()->json(['data' => ['summary' => ['count' => 0, 'average' => null, 'breakdown' => null], 'reviews' => []]]);
        }
        $reviews = TailorReview::query()->published()->where('tailor_id', $request->user()->id)
            ->with(['response', 'customer'])->latest('submitted_at')->limit(100)->get();

        return response()->json(['data' => [
            'summary' => $profile->reviewSummary(),
            'reviews' => $reviews->map(fn (TailorReview $r) => $this->present($r, 'public'))->values(),
        ]]);
    }

    public function respond(Request $request, TailorReview $review): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:10', 'max:1000']]);
        $response = $this->reviews->respond($request->user(), $review, $data['body']);

        return response()->json(['data' => ['id' => $response->id, 'body' => $response->body]], 201);
    }

    // ---- Public --------------------------------------------------------------------------

    /** Published reviews only. Reviewer names are shortened and no order, contact or measurement data is shown. */
    public function publicList(string $slug): JsonResponse
    {
        $profile = TailorProfile::query()->where('slug', $slug)->firstOrFail();
        $reviews = TailorReview::query()->published()->where('tailor_id', $profile->user_id)
            ->with(['response', 'customer', 'photos'])->latest('submitted_at')->limit(50)->get();

        return response()->json(['data' => [
            'summary' => $profile->reviewSummary(),
            'reviews' => $reviews->map(fn (TailorReview $r) => $this->present($r, 'public'))->values(),
        ]]);
    }

    /** Only approved photos of published reviews are served. */
    public function publicPhoto(TailorReviewPhoto $photo): Response
    {
        abort_unless($photo->status === TailorReviewPhoto::APPROVED, 404);
        abort_unless(TailorReview::query()->published()->whereKey($photo->review_id)->exists(), 404);

        return $this->photos->response($photo->path, $photo->mime);
    }

    // ---- Admin ---------------------------------------------------------------------------

    public function adminQueue(): JsonResponse
    {
        $reports = TailorReviewReport::query()->where('status', 'open')->with(['review.customer', 'review.tailor'])->oldest()->limit(200)->get();
        $appeals = TailorReviewAppeal::query()->where('status', 'open')->with(['review.customer', 'review.tailor'])->oldest()->limit(200)->get();
        $photos = TailorReviewPhoto::query()->where('status', TailorReviewPhoto::PENDING)->with('review')->oldest()->limit(200)->get();

        return response()->json(['data' => [
            'reports' => $reports->map(fn ($r) => [
                'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details, 'created_at' => $r->created_at?->toIso8601String(),
                'review' => $this->present($r->review, 'admin'),
            ])->values(),
            'appeals' => $appeals->map(fn ($a) => [
                'id' => $a->id, 'reason' => $a->reason, 'created_at' => $a->created_at?->toIso8601String(),
                'review' => $this->present($a->review, 'admin'),
            ])->values(),
            'photos' => $photos->map(fn ($p) => [
                'id' => $p->id, 'review_id' => $p->review_id, 'review_status' => $p->review->status,
                'file_url' => url('/api/v1/admin/tailor-review-photos/'.$p->id.'/file'),
            ])->values(),
        ]]);
    }

    public function adminPhotoFile(TailorReviewPhoto $photo): Response
    {
        return $this->photos->response($photo->path, $photo->mime);
    }

    public function adminModerate(Request $request, TailorReview $review): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([TailorReview::PUBLISHED, TailorReview::HIDDEN])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $review = $this->reviews->moderate($request->user(), $review, $data['to'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $review->id, 'status' => $review->status]]);
    }

    public function adminDecideReport(Request $request, TailorReviewReport $report): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'dismissed'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $report = $this->reviews->decideReport($request->user(), $report, $data['decision'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]]);
    }

    public function adminDecidePhoto(Request $request, TailorReviewPhoto $photo): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in([TailorReviewPhoto::APPROVED, TailorReviewPhoto::REJECTED])]]);
        $photo = $this->reviews->decidePhoto($request->user(), $photo, $data['decision']);

        return response()->json(['data' => ['id' => $photo->id, 'status' => $photo->status]]);
    }

    public function adminDecideAppeal(Request $request, TailorReviewAppeal $appeal): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['restored', 'upheld'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $appeal = $this->reviews->decideAppeal($request->user(), $appeal, $data['decision'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]]);
    }

    // ---- Helpers -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function contentRules(): array
    {
        $score = ['nullable', 'integer', 'between:1,5'];

        return [
            'stars' => ['required', 'integer', 'between:1,5'],
            'quality' => $score,
            'communication' => $score,
            'delivery_time' => $score,
            'value_for_money' => $score,
            'body' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * What each audience may see. Public: no customer contact, order reference or measurements.
     * Owner (the reviewer): their own text, its history and reply. Admin: adds status and moderation fields.
     */
    private function present(TailorReview $r, string $audience): array
    {
        $name = trim((string) $r->customer?->name);
        $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)).'.' : '';

        $data = [
            'id' => $r->id,
            'stars' => $r->stars,
            'ratings' => [
                'quality' => $r->quality, 'communication' => $r->communication,
                'delivery_time' => $r->delivery_time, 'value_for_money' => $r->value_for_money,
            ],
            'body' => $r->body,
            'reviewer_label' => 'Verified buyer'.($initial !== '' ? ' · '.$initial : ''),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'corrected' => $r->correction_count > 0,
            'corrected_at' => $r->corrected_at?->toIso8601String(),
            'response' => $r->response ? ['body' => $r->response->body, 'created_at' => $r->response->created_at?->toIso8601String(), 'responded_to_version' => $r->response->responded_to_version] : null,
            'photos' => $r->photos
                ->filter(fn (TailorReviewPhoto $p) => $audience === 'admin' || $p->status === TailorReviewPhoto::APPROVED)
                ->map(fn (TailorReviewPhoto $p) => [
                    'id' => $p->id, 'status' => $p->status,
                    'url' => $audience === 'admin'
                        ? url('/api/v1/admin/tailor-review-photos/'.$p->id.'/file')
                        : url('/api/v1/tailor-review-photos/'.$p->id),
                ])->values(),
        ];

        if ($audience === 'owner' || $audience === 'admin') {
            $data['status'] = $r->status;
            $data['hidden_reason'] = $r->hidden_reason;
            $data['original'] = $r->relationLoaded('revisions') && $r->revisions->isNotEmpty()
                ? $r->revisions->map(fn (TailorReviewRevision $v) => [
                    'version' => $v->version, 'stars' => $v->stars, 'body' => $v->body,
                    'quality' => $v->quality, 'communication' => $v->communication,
                    'delivery_time' => $v->delivery_time, 'value_for_money' => $v->value_for_money,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->values()
                : null;
            $data['can_correct'] = $audience === 'owner' && $r->status === TailorReview::PUBLISHED && $r->correction_count === 0
                && $r->submitted_at?->copy()->addHours(TailorReviewService::CORRECTION_WINDOW_HOURS)->isFuture();
        }

        if ($audience === 'admin') {
            $data['status'] = $r->status;
            $data['hidden_reason'] = $r->hidden_reason;
            $data['order_id'] = $r->order_id;
            $data['tailor_id'] = $r->tailor_id;
            $data['customer_id'] = $r->customer_id;
            $data['correction_count'] = $r->correction_count;
        }

        return $data;
    }
}
