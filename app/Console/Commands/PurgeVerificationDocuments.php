<?php

namespace App\Console\Commands;

use App\Domain\Shop\VendorVerification;
use App\Domain\Tailors\TailorVerification;
use App\Models\FabricVendor;
use App\Models\TailorProfile;
use App\Models\TailorVerificationDocument;
use App\Models\VendorVerificationDocument;
use App\Services\PrivatePhotoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Deletes identity files once we no longer need them:
 * - a rejected document is deleted 30 days after the decision;
 * - ID and selfie files of a tailor or shop that is no longer verified (and is not pending a decision)
 *   are deleted 30 days after the last change to that profile or its files.
 */
class PurgeVerificationDocuments extends Command
{
    public const RETENTION_DAYS = 30;

    protected $signature = 'verification:purge-documents';

    protected $description = 'Delete rejected identity documents and biometric files that are no longer needed';

    public function handle(PrivatePhotoService $photos): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;

        foreach ([TailorVerificationDocument::class, VendorVerificationDocument::class] as $model) {
            $model::query()->where('status', 'rejected')->where('reviewed_at', '<', $cutoff)
                ->chunkById(200, function ($docs) use ($photos, &$deleted) {
                    foreach ($docs as $doc) {
                        $photos->delete($doc->path);
                        $doc->delete();
                        $deleted++;
                    }
                });
        }

        TailorProfile::query()
            ->whereNotIn('verification_status', [TailorVerification::VERIFIED, TailorVerification::PENDING])
            ->chunkById(200, function ($profiles) use ($photos, $cutoff, &$deleted) {
                foreach ($profiles as $profile) {
                    $deleted += $this->purgeBiometrics($profile->documents(), TailorVerification::BIOMETRIC, $profile->updated_at, $cutoff, $photos);
                }
            });

        FabricVendor::query()
            ->whereNotIn('verification_status', [VendorVerification::VERIFIED, VendorVerification::PENDING])
            ->chunkById(200, function ($vendors) use ($photos, $cutoff, &$deleted) {
                foreach ($vendors as $vendor) {
                    $deleted += $this->purgeBiometrics($vendor->documents(), VendorVerification::DOCUMENT_BIOMETRIC, $vendor->updated_at, $cutoff, $photos);
                }
            });

        $this->info("Deleted {$deleted} identity file(s).");

        return self::SUCCESS;
    }

    /**
     * The clock starts at the latest of the profile's last change and the latest activity on its files.
     * Nothing is deleted while any of that is inside the retention window.
     */
    private function purgeBiometrics(HasMany $documents, array $kinds, ?Carbon $profileChangedAt, Carbon $cutoff, PrivatePhotoService $photos): int
    {
        $docs = $documents->whereIn('kind', $kinds)->get();
        if ($docs->isEmpty()) {
            return 0;
        }

        $lastActivity = $docs
            ->map(fn ($doc) => $doc->reviewed_at ?? $doc->created_at)
            ->push($profileChangedAt)
            ->filter()
            ->max();

        if ($lastActivity === null || $lastActivity->greaterThanOrEqualTo($cutoff)) {
            return 0;
        }

        foreach ($docs as $doc) {
            $photos->delete($doc->path);
            $doc->delete();
        }

        return $docs->count();
    }
}
