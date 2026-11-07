<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Domain\Tailors\TailorVerification;
use App\Models\AdminAction;
use App\Models\TailorProfile;
use App\Models\TailorVerificationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Tailor identity checks. Every decision is an explicit admin action and is written to admin_actions. */
class TailorVerificationService
{
    public function __construct(
        private readonly PrivatePhotoService $photos,
        private readonly Notifier $notifier,
    ) {}

    public function upload(User $user, string $kind, UploadedFile $file, bool $consent): TailorVerificationDocument
    {
        $profile = $user->tailorProfile ?? throw new DomainRuleViolation('Save your tailor profile before uploading documents.', 'profile_missing');
        if ($profile->verification_status === TailorVerification::VERIFIED) {
            throw new DomainRuleViolation('Your profile is already verified. Contact OSAPS support to change a document.', 'already_verified');
        }
        if (in_array($kind, TailorVerification::BIOMETRIC, true) && ! $consent) {
            throw new DomainRuleViolation('We need your consent before we take your ID or selfie.', 'consent_required');
        }

        $stored = $this->photos->store($file, 'tailor-verification/'.$profile->id);

        return DB::transaction(function () use ($profile, $kind, $stored, $consent) {
            // Replace an earlier upload of this kind, unless an admin has already approved it.
            TailorVerificationDocument::query()->where('tailor_profile_id', $profile->id)->where('kind', $kind)
                ->where('status', '!=', 'approved')->get()
                ->each(function (TailorVerificationDocument $old) {
                    $this->photos->delete($old->path);
                    $old->delete();
                });

            if ($consent && in_array($kind, TailorVerification::BIOMETRIC, true)) {
                $profile->forceFill(['verification_consent_at' => now()])->save();
            }

            return TailorVerificationDocument::query()->create([
                'tailor_profile_id' => $profile->id, 'kind' => $kind, 'path' => $stored['path'],
                'mime' => $stored['mime'], 'size_bytes' => $stored['size_bytes'], 'status' => 'submitted',
            ]);
        });
    }

    public function requestReview(User $user): TailorProfile
    {
        $profile = $user->tailorProfile ?? throw new DomainRuleViolation('Save your tailor profile first.', 'profile_missing');
        $status = $profile->verification_status ?: TailorVerification::UNVERIFIED;
        if ($status === TailorVerification::PENDING || $status === TailorVerification::VERIFIED) {
            return $profile;
        }
        $missing = TailorVerification::missing($this->statusByKind($profile), needApproved: false);
        if ($missing !== []) {
            throw new DomainRuleViolation('Upload: '.implode(', ', array_map(fn ($k) => TailorVerification::KIND_LABELS[$k], $missing)).'.', 'verification_incomplete');
        }
        TailorVerification::assert($status, TailorVerification::PENDING, 'tailor');

        $profile->forceFill(['verification_status' => TailorVerification::PENDING, 'verification_requested_at' => now()])->save();

        return $profile;
    }

    public function reviewDocument(User $admin, TailorVerificationDocument $doc, string $decision, ?string $note): TailorVerificationDocument
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainRuleViolation('Decision must be approved or rejected.', 'bad_decision');
        }
        if ($decision === 'rejected' && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say why the document is rejected, so the tailor can fix it.', 'verification_note_required');
        }

        return DB::transaction(function () use ($admin, $doc, $decision, $note) {
            $doc = TailorVerificationDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $doc->forceFill([
                'status' => $decision, 'reviewed_by' => $admin->id, 'reviewed_at' => now(),
                'review_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
            ])->save();
            AdminAction::record($admin, 'tailor_document_'.$decision, $doc, $note, ['kind' => $doc->kind]);

            return $doc;
        });
    }

    public function review(User $admin, TailorProfile $profile, string $to, ?string $note): TailorProfile
    {
        return DB::transaction(function () use ($admin, $profile, $to, $note) {
            $profile = TailorProfile::query()->lockForUpdate()->findOrFail($profile->id);
            TailorVerification::assertNoteGiven($to, $note);
            $from = $profile->verification_status ?: TailorVerification::UNVERIFIED;
            TailorVerification::assert($from, $to, 'admin');

            if ($to === TailorVerification::VERIFIED) {
                $missing = TailorVerification::missing($this->statusByKind($profile), needApproved: true);
                if ($missing !== []) {
                    throw new DomainRuleViolation('Approve every required document first: '.implode(', ', $missing).'.', 'verification_incomplete');
                }
            }

            $profile->forceFill([
                'verification_status' => $to,
                'verification_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
                'verified_at' => $to === TailorVerification::VERIFIED ? now() : $profile->verified_at,
            ])->save();
            AdminAction::record($admin, 'tailor_'.$to, $profile, $note);

            $text = match ($to) {
                TailorVerification::VERIFIED => 'Your identity and workshop checks are approved. Your verified badge is now on your profile.',
                TailorVerification::REJECTED => 'Your profile needs changes before it can be verified: '.$note,
                TailorVerification::SUSPENDED => 'Your profile is paused while we check it: '.$note,
                default => null,
            };
            if ($text !== null) {
                $this->notifier->notify($profile->user_id, 'tailor_verification', 'Verification update', $text, '/tailor');
            }

            return $profile;
        });
    }

    /** @return array<string, string> latest status per kind */
    public function statusByKind(TailorProfile $profile): array
    {
        return $profile->documents()->orderByDesc('id')->get()
            ->unique('kind')->mapWithKeys(fn ($d) => [$d->kind => $d->status])->all();
    }
}
