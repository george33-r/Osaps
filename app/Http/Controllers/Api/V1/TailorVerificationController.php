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
            'recheck' => ['due_at' => $profile->recheck_due_at?->toIso8601String(), 'state' => $profile->verification_status === TailorVerification::VERIFIED ? \App\Domain\Verification\Recheck::state($profile->recheck_due_at, now()) : null],
        ]]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_merge(TailorVerification::REQUIRED, TailorVerification::OPTIONAL))],
            'file' => ['required', 'file', 'max:8192'],
            'consent' => ['nullable', 'boolean'],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ]);
        $doc = $this->service->upload($request->user(), $data['kind'], $request->file('file'), (bool) ($data['consent'] ?? false), $data['expires_on'] ?? null);

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

    /** Every tailor account, including those that have not set up a profile yet. */
    public function directory(Request $request): JsonResponse
    {
        $status = $request->query('status');
        abort_unless($status === null || $status === '' || in_array($status, array_keys(TailorVerification::LABELS), true) || $status === 'no_profile', 422);

        $users = \App\Models\User::query()->where('role', 'tailor')->with(['tailorProfile.documents'])
            ->orderBy('name')->limit(500)->get()
            ->map(function (\App\Models\User $u) {
                $p = $u->tailorProfile;
                $docs = $p ? $p->documents->unique('kind') : collect();
                $missing = $p ? TailorVerification::missing($this->service->statusByKind($p), needApproved: false) : TailorVerification::REQUIRED;

                return [
                    'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
                    'profile_id' => $p?->id, 'business_name' => $p?->business_name, 'city' => $p?->city,
                    'status' => $p ? $p->verification_status : 'no_profile',
                    'has_photo' => (bool) ($p?->profile_photo_path),
                    'documents_uploaded' => $docs->count(),
                    'missing' => $missing,
                    'can_override' => $p !== null && $p->verification_status === TailorVerification::PENDING && $missing === [],
                ];
            })
            ->when($status, fn ($c) => $c->where('status', $status))->values();

        return response()->json(['data' => $users]);
    }

    /** Approve a tailor whose documents are all uploaded but whose review was missed. The reason is required and logged. */
    public function override(Request $request, TailorProfile $tailorProfile): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:300']]);
        $profile = $this->service->overrideVerify($request->user(), $tailorProfile, $data['note']);

        return response()->json(['data' => ['id' => $profile->id, 'status' => $profile->verification_status]]);
    }

    /** Send one message to selected tailors. Queued; the reason and recipient count go in the log. */
    public function bulkMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'tailor')],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:3000'],
        ]);
        $emails = \App\Models\User::query()->whereIn('id', $data['user_ids'])->pluck('email');
        foreach ($emails as $email) {
            \Illuminate\Support\Facades\Mail::to($email)->queue(new \App\Mail\AdminBroadcast($data['subject'], $data['body']));
        }
        \App\Models\AdminAction::record($request->user(), 'tailor_bulk_mail', $request->user(), mb_substr($data['subject'], 0, 150), ['recipients' => $emails->count()]);

        return response()->json(['data' => ['queued' => $emails->count()]], 202);
    }

    /** Public: the approved selfie, only while the profile is verified. */
    public function face(string $slug): Response
    {
        $profile = TailorProfile::query()->where('slug', $slug)->where('verification_status', TailorVerification::VERIFIED)->firstOrFail();
        abort_unless($profile->badgeActive(), 404);
        $selfie = $profile->approvedSelfie() ?? abort(404);

        return $this->photos->response($selfie->path, $selfie->mime);
    }
}
