<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\TailorFeature;
use App\Models\TailorProfile;
use App\Models\User;

/**
 * Tailor of the week. Picked automatically from real data, then an admin may replace the pick.
 * Rule: verified, accepting work, at least 3 published reviews averaging 4.0 or more, and at least 3 completed orders.
 * Score = 60% average rating + 40% on-time delivery rate. Ties go to the earlier profile.
 */
class TailorFeatureService
{
    public const MIN_REVIEWS = 3;

    public const MIN_AVERAGE = 4.0;

    public const MIN_COMPLETED = 3;

    public function weekStart(): string
    {
        return now()->startOfWeek()->toDateString();
    }

    /** This week's featured tailor. The first request of the week makes the automatic pick and keeps it. */
    public function current(): ?TailorFeature
    {
        $week = $this->weekStart();
        $row = TailorFeature::query()->where('week_start', $week)->first();
        if ($row === null) {
            $pick = $this->autoPick();
            if ($pick === null) {
                return null;
            }
            $row = TailorFeature::query()->firstOrCreate(['week_start' => $week], [
                'tailor_profile_id' => $pick->id, 'source' => TailorFeature::AUTO,
            ]);
        }

        return $row->load('tailorProfile');
    }

    public function override(User $admin, TailorProfile $profile, string $reason): TailorFeature
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new DomainRuleViolation('Give the reason for the choice (at least 10 characters).', 'note_required');
        }
        if (! $profile->badgeActive()) {
            throw new DomainRuleViolation('Only a verified tailor with active badges can be featured.', 'not_verified');
        }

        $row = TailorFeature::query()->updateOrCreate(['week_start' => $this->weekStart()], [
            'tailor_profile_id' => $profile->id,
            'source' => TailorFeature::ADMIN,
            'reason' => mb_substr($reason, 0, 300),
            'chosen_by' => $admin->id,
        ]);
        AdminAction::record($admin, 'tailor_of_week_set', $profile, $reason);

        return $row->load('tailorProfile');
    }

    private function autoPick(): ?TailorProfile
    {
        $best = null;
        $bestScore = -1.0;
        TailorProfile::query()->acceptingWork()->where('verification_status', 'verified')->get()
            ->each(function (TailorProfile $p) use (&$best, &$bestScore) {
                if (! $p->badgeActive()) {
                    return;
                }
                $summary = $p->reviewSummary();
                $record = $p->trackRecord();
                if ($summary['count'] < self::MIN_REVIEWS || $summary['average'] === null || $summary['average'] < self::MIN_AVERAGE || $record['completed_orders'] < self::MIN_COMPLETED) {
                    return;
                }
                $onTime = $record['on_time_orders'] / $record['completed_orders'];
                $score = ($summary['average'] / 5) * 0.6 + $onTime * 0.4;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $p;
                }
            });

        return $best;
    }
}
