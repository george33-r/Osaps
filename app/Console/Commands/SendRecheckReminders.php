<?php

namespace App\Console\Commands;

use App\Domain\Shop\VendorVerification;
use App\Domain\Tailors\TailorVerification;
use App\Domain\Verification\Recheck;
use App\Models\FabricVendor;
use App\Models\TailorProfile;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Tells verified tailors and shops when their six-week check is due, and again once it is overdue.
 * Each cycle gets one reminder, so nobody is messaged every day.
 */
class SendRecheckReminders extends Command
{
    protected $signature = 'verification:recheck-reminders';

    protected $description = 'Remind verified tailors and shops about their six-week check';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;
        $now = now();

        TailorProfile::query()->where('verification_status', TailorVerification::VERIFIED)
            ->whereNotNull('recheck_due_at')->where('recheck_due_at', '<=', $now->copy()->addDays(Recheck::WARNING_DAYS))
            ->chunkById(200, function ($profiles) use ($notifier, $now, &$sent) {
                foreach ($profiles as $profile) {
                    $state = Recheck::state($profile->recheck_due_at, $now);
                    if (! $this->dueForReminder($profile->recheck_reminded_at, $profile->recheck_due_at, $now)) {
                        continue;
                    }
                    $notifier->notify($profile->user_id, 'verification_recheck', 'Six-week check', $this->text($state, $profile->recheck_due_at), '/tailor/verify');
                    $profile->forceFill(['recheck_reminded_at' => $now])->saveQuietly();
                    $sent++;
                }
            });

        FabricVendor::query()->where('verification_status', VendorVerification::VERIFIED)
            ->whereNotNull('recheck_due_at')->where('recheck_due_at', '<=', $now->copy()->addDays(Recheck::WARNING_DAYS))
            ->chunkById(200, function ($vendors) use ($notifier, $now, &$sent) {
                foreach ($vendors as $vendor) {
                    $state = Recheck::state($vendor->recheck_due_at, $now);
                    if (! $this->dueForReminder($vendor->recheck_reminded_at, $vendor->recheck_due_at, $now)) {
                        continue;
                    }
                    $notifier->notify($vendor->user_id, 'vendor_recheck', 'Six-week check', $this->text($state, $vendor->recheck_due_at), '/vendor');
                    $vendor->forceFill(['recheck_reminded_at' => $now])->saveQuietly();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} re-check reminder(s).");

        return self::SUCCESS;
    }

    /** One reminder per cycle: none yet this cycle, and the check is inside the warning window or overdue. */
    private function dueForReminder(?\DateTimeInterface $remindedAt, \DateTimeInterface $due, \DateTimeInterface $now): bool
    {
        if (! in_array(Recheck::state($due, $now), [Recheck::WARNING, Recheck::OVERDUE], true)) {
            return false;
        }
        $cycleStart = (new \DateTimeImmutable('@'.$due->getTimestamp()))->modify('-'.Recheck::INTERVAL_DAYS.' days');

        return $remindedAt === null || $remindedAt < $cycleStart;
    }

    private function text(string $state, \DateTimeInterface $due): string
    {
        if ($state === Recheck::OVERDUE) {
            return 'Your six-week check is overdue, so your badges are hidden. Upload a current ID and CAC certificate to restart it.';
        }

        return 'Your six-week check is due on '.(new \DateTimeImmutable('@'.$due->getTimestamp()))->setTimezone(new \DateTimeZone(config('app.timezone')))->format('j M Y').'. Upload a current ID and CAC certificate before then.';
    }
}
