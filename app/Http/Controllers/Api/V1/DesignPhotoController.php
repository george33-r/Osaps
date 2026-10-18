<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Support\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\JobRequest;
use App\Models\JobRequestPhoto;
use App\Models\OfferMessage;
use App\Services\PrivatePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sample designs on a tailoring request (up to 4), and photos sent in the customer–tailor chat.
 * Request photos are seen by the customer and by tailors who can see the request; chat photos
 * only by the two people in that conversation.
 */
class DesignPhotoController extends Controller
{
    public const MAX_PER_REQUEST = 4;

    public function __construct(private readonly PrivatePhotoService $photos) {}

    public function store(Request $request, JobRequest $jobRequest): JsonResponse
    {
        abort_unless($request->user()->id === $jobRequest->customer_id, 403);
        $request->validate(['photo' => ['required', 'file', 'max:8192'], 'caption' => ['nullable', 'string', 'max:140']]);
        if (! $jobRequest->isOpen()) {
            throw new DomainRuleViolation('This request is closed, so photos cannot be added.', 'request_closed');
        }
        if ($jobRequest->photos()->count() >= self::MAX_PER_REQUEST) {
            throw new DomainRuleViolation('Up to '.self::MAX_PER_REQUEST.' design photos per request. Remove one first.', 'photo_limit');
        }
        $saved = $this->photos->store($request->file('photo'), 'designs/'.$jobRequest->id);
        $photo = $jobRequest->photos()->create($saved + ['caption' => $request->input('caption'), 'sort' => $jobRequest->photos()->count()]);

        return response()->json(['data' => self::present($photo)], 201);
    }

    public function destroy(Request $request, JobRequest $jobRequest, JobRequestPhoto $photo): JsonResponse
    {
        abort_unless($request->user()->id === $jobRequest->customer_id && $photo->job_request_id === $jobRequest->id, 403);
        $this->photos->delete($photo->path);
        $photo->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function show(Request $request, JobRequestPhoto $photo)
    {
        $photo->loadMissing('jobRequest');
        Gate::authorize('view', $photo->jobRequest);

        return $this->photos->response($photo->path, $photo->mime);
    }

    public function message(Request $request, OfferMessage $message)
    {
        $message->loadMissing('offer.jobRequest');
        Gate::authorize('view', $message->offer);
        abort_if($message->photo_path === null, 404);

        return $this->photos->response($message->photo_path, (string) $message->photo_mime);
    }

    /** @return array{id:int, url:string, caption:?string} */
    public static function present(JobRequestPhoto $p): array
    {
        return ['id' => $p->id, 'url' => $p->url(), 'caption' => $p->caption];
    }
}
