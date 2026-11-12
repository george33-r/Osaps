<?php

namespace App\Services;

use App\Domain\Shop\VendorVerification;
use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\FabricVendor;
use App\Models\User;
use App\Models\VendorVerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Documents a fabric shop uploads for its verification: the CAC certificate and the owner's ID.
 * Uploads are closed while a review is pending, so an admin never looks at a file that changes.
 */
class VendorVerificationDocumentService
{
    public function __construct(
        private readonly PrivatePhotoService $photos,
        private readonly Notifier $notifier,
    ) {}

    public function upload(User $user, string $kind, UploadedFile $file, bool $consent): VendorVerificationDocument
    {
        $vendor = $user->fabricVendor ?? throw new DomainRuleViolation('Save your shop details before uploading documents.', 'shop_missing');
        $status = $vendor->verification_status ?: VendorVerification::UNVERIFIED;
        if ($status === VendorVerification::VERIFIED) {
            throw new DomainRuleViolation('Your shop is already verified. Contact OSAPS support to change a document.', 'already_verified');
        }
        if ($status === VendorVerification::PENDING) {
            throw new DomainRuleViolation('Your documents are with the OSAPS team. You can upload again after they decide.', 'review_in_progress');
        }
        if (in_array($kind, VendorVerification::DOCUMENT_BIOMETRIC, true) && ! $consent) {
            throw new DomainRuleViolation('We need your consent before we take your ID.', 'consent_required');
        }

        $stored = $this->photos->store($file, 'vendor-verification/'.$vendor->id, allowPdf: true);

        return DB::transaction(function () use ($vendor, $kind, $stored, $consent) {
            // Replace an earlier upload of this kind, unless an admin has already approved it.
            VendorVerificationDocument::query()->where('fabric_vendor_id', $vendor->id)->where('kind', $kind)
                ->where('status', '!=', 'approved')->get()
                ->each(function (VendorVerificationDocument $old) {
                    $this->photos->delete($old->path);
                    $old->delete();
                });

            if ($consent && in_array($kind, VendorVerification::DOCUMENT_BIOMETRIC, true)) {
                $vendor->forceFill(['verification_consent_at' => now()])->save();
            }

            return VendorVerificationDocument::query()->create([
                'fabric_vendor_id' => $vendor->id, 'kind' => $kind, 'path' => $stored['path'],
                'mime' => $stored['mime'], 'size_bytes' => $stored['size_bytes'], 'status' => 'submitted',
            ]);
        });
    }

    public function reviewDocument(User $admin, VendorVerificationDocument $doc, string $decision, ?string $note): VendorVerificationDocument
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainRuleViolation('Decision must be approved or rejected.', 'bad_decision');
        }
        if ($decision === 'rejected' && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say why the document is rejected, so the shop can fix it.', 'verification_note_required');
        }

        return DB::transaction(function () use ($admin, $doc, $decision, $note) {
            $doc = VendorVerificationDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $doc->forceFill([
                'status' => $decision, 'reviewed_by' => $admin->id, 'reviewed_at' => now(),
                'review_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
            ])->save();
            AdminAction::record($admin, 'vendor_document_'.$decision, $doc, $note, ['kind' => $doc->kind]);

            if ($decision === 'rejected') {
                $vendor = FabricVendor::query()->find($doc->fabric_vendor_id);
                $this->notifier->notify(
                    $vendor?->user_id,
                    'vendor_verification',
                    'A document needs a new upload',
                    VendorVerification::DOCUMENT_LABELS[$doc->kind].': '.mb_substr(trim((string) $note), 0, 300),
                    '/vendor',
                );
            }

            return $doc;
        });
    }

    /** @return array<string, string> latest status per kind */
    public function statusByKind(FabricVendor $vendor): array
    {
        return $vendor->documents()->orderByDesc('id')->get()
            ->unique('kind')->mapWithKeys(fn (VendorVerificationDocument $d) => [$d->kind => $d->status])->all();
    }
}
