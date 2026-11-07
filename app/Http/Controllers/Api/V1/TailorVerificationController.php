<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tailors\TailorVerification;
use App\Http\Controllers\Controller;
use App\Models\TailorProfile;
use App\Models\TailorVerificationDocument;
use App\Services\PrivatePhotoService;
use App\Services\TailorVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TailorVerificationController extends Controller
{
    public function __construct(
        private readonly TailorVerificationService $service,
        private readonly PrivatePhotoService $photos,
    ) {}

    // ---- Tailor -------------------------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        $profile = $request->user()->tailorProfile;
        if ($profile === null) {
            return response()->json(['data' => null]);
        }
        $docs = $profile->documents()->orderByDesc('id')->get()->unique('kind');

        return response()->json(['data' => [
            'status' => $profile->verification_status, 'label' => TailorVerification::LABELS[$profile->verification_status] ?? $profile->verification_status,
            'note' => $profile->verification_note,
            'consent_given' => $profile->verification_consent_at !== null,
            'documents' => collect(array_merge(TailorVerification::REQUIRED, TailorVerification::OPTIONAL))->map(fn (string $kind) => [
                'kind' => $kind, 'label' => TailorVerification::KIND_LABELS[$kind],
                'required' => in_array($kind, TailorVerification::REQUIRED, true),
                'status' => $docs->firstWhere('kind', $kind)?->status,
            ])->values(),
            'missing' => TailorVerification::missing($this->service->statusByKind($profile), needApproved: false),
        ]]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_merge(TailorVerification::REQUIRED, TailorVerification::OPTIONAL))],
            'file' => ['required', 'file', 'max:8192'],
            'consent' => ['nullable', 'boolean'],
        ]);
        $doc = $this->service->upload($request->user(), $data['kind'], $request->file('file'), (bool) ($data['consent'] ?? false));

        return response()->json(['data' => ['kind' => $doc->kind, 'status' => $doc->status]], 201);
    }

    public function submit(Request $request): JsonResponse
    {
        $profile = $this->service->requestReview($request->user());

        return response()->json(['data' => ['status' => $profile->verification_status]]);
    }

    // ---- Admin ----------------------------------------------------------------------------

    public function queue(Request $request): JsonResponse
    {
        $status = $request->query('status', TailorVerification::PENDING);
        abort_unless(in_array($status, array_keys(TailorVerification::LABELS), true), 422);
        $profiles = TailorProfile::query()->with(['user:id,name,email,phone', 'documents'])
            ->where('verification_status', $status)->orderBy('verification_requested_at')->limit(200)->get();

        return response()->json(['data' => $profiles->map(fn (TailorProfile $p) => [
            'id' => $p->id, 'business_name' => $p->business_name, 'city' => $p->city,
            'owner' => ['name' => $p->user?->name, 'email' => $p->user?->email, 'phone' => $p->user?->phone],
            'status' => $p->verification_status, 'requested_at' => $p->verification_requested_at?->toIso8601String(),
            'documents' => $p->documents->sortByDesc('id')->unique('kind')->values()->map(fn (TailorVerificationDocument $d) => [
                'id' => $d->id, 'kind' => $d->kind, 'label' => TailorVerification::KIND_LABELS[$d->kind] ?? $d->kind,
                'status' => $d->status, 'review_note' => $d->review_note,
                'file_url' => url('/api/v1/admin/tailor-documents/'.$d->id.'/file'),
            ])->values(),
        ])->values()]);
    }

    public function documentFile(TailorVerificationDocument $document): Response
    {
        return $this->photos->response($document->path, $document->mime);
    }

    public function reviewDocument(Request $request, TailorVerificationDocument $document): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $doc = $this->service->reviewDocument($request->user(), $document, $data['decision'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $doc->id, 'status' => $doc->status]]);
    }

    public function reviewProfile(Request $request, TailorProfile $tailorProfile): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([TailorVerification::VERIFIED, TailorVerification::REJECTED, TailorVerification::SUSPENDED])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $profile = $this->service->review($request->user(), $tailorProfile, $data['to'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $profile->id, 'status' => $profile->verification_status]]);
    }

    /** Public: the approved selfie, only while the profile is verified. */
    public function face(string $slug): Response
    {
        $profile = TailorProfile::query()->where('slug', $slug)->where('verification_status', TailorVerification::VERIFIED)->firstOrFail();
        $selfie = $profile->approvedSelfie() ?? abort(404);

        return $this->photos->response($selfie->path, $selfie->mime);
    }
}
