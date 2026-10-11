<?php

namespace App\Services;

use App\Domain\Matching\TailorScorer;
use App\Models\JobRequest;
use App\Models\TailorProfile;
use Illuminate\Support\Collection;

class MatchingService
{
    public function __construct(private readonly TailorScorer $scorer) {}

    /**
     * Rank tailors for a request. Track records are computed from real orders.
     * Phase 1 computes per tailor; cache or precompute once tailor counts grow.
     *
     * @return Collection<int, array{profile: TailorProfile, match: array}>
     */
    public function rankFor(JobRequest $request, int $limit = 12): Collection
    {
        $job = [
            'garment_category' => $request->garment_category,
            'city' => $request->city,
            'style_tags' => $request->style_tags ?? [],
        ];

        return TailorProfile::query()->acceptingWork()
            ->where('user_id', '!=', $request->customer_id)
            ->get()
            ->map(fn (TailorProfile $p) => [
                'profile' => $p,
                'match' => $this->scorer->score($job, $this->signals($p)),
            ])
            ->sortByDesc(fn ($row) => $row['match']['score'])
            ->take($limit)
            ->values();
    }

    public function scoreProfileFor(TailorProfile $profile, JobRequest $request): array
    {
        return $this->scorer->score([
            'garment_category' => $request->garment_category,
            'city' => $request->city,
            'style_tags' => $request->style_tags ?? [],
        ], $this->signals($profile));
    }

    /** @var array<int, array> per-request memo so a tailor's track record is queried once */
    private array $signalCache = [];

    private function signals(TailorProfile $p): array
    {
        return $this->signalCache[$p->id] ??= [
            'specialties' => $p->specialties ?? [],
            'service_cities' => $p->service_cities ?? [],
            'portfolio_tags' => $p->portfolio_tags ?? [],
            'weekly_capacity' => $p->weekly_capacity,
            'median_response_minutes' => $p->median_response_minutes,
        ] + $p->trackRecord();
    }
}
