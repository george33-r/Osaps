<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shop\VendorVerification;
use App\Http\Controllers\Controller;
use App\Models\VendorVerificationDocument;
use App\Services\PrivatePhotoService;
use App\Services\VendorVerificationDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class VendorDocumentController extends Controller
{
    public function __construct(
        private readonly VendorVerificationDocumentService $service,
        private readonly PrivatePhotoService $photos,
    ) {}

    // ---- Shop owner -----------------------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        if ($vendor === null) {
            return response()->json(['data' => null]);
        }
        $docs = $vendor->documents()->orderByDesc('id')->get()->unique('kind');

        return response()->json(['data' => [
            'consent_given' => $vendor->verification_consent_at !== null,
            'documents' => collect(VendorVerification::DOCUMENT_REQUIRED)->map(fn (string $kind) => [
                'kind' => $kind,
                'label' => VendorVerification::DOCUMENT_LABELS[$kind],
                'biometric' => in_array($kind, VendorVerification::DOCUMENT_BIOMETRIC, true),
                'status' => $docs->firstWhere('kind', $kind)?->status,
                'review_note' => $docs->firstWhere('kind', $kind)?->review_note,
            ])->values(),
            'missing' => VendorVerification::missingDocuments($this->service->statusByKind($vendor), needApproved: false),
        ]]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(VendorVerification::DOCUMENT_REQUIRED)],
            'file' => ['required', 'file', 'max:8192'],
            'consent' => ['nullable', 'boolean'],
        ]);
        $doc = $this->service->upload($request->user(), $data['kind'], $request->file('file'), (bool) ($data['consent'] ?? false));

        return response()->json(['data' => ['kind' => $doc->kind, 'status' => $doc->status]], 201);
    }

    // ---- Admin ----------------------------------------------------------------------------

    public function documentFile(VendorVerificationDocument $document): Response
    {
        return $this->photos->response($document->path, $document->mime);
    }

    public function reviewDocument(Request $request, VendorVerificationDocument $document): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $doc = $this->service->reviewDocument($request->user(), $document, $data['decision'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $doc->id, 'status' => $doc->status]]);
    }
}
