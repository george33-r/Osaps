<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

/** Public tailor profile. Track record numbers come only from completed platform orders. */
class TailorResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $record = $this->trackRecord();
        $labels = config('marketplace.garment_categories');

        return [
            'slug' => $this->slug,
            'business_name' => $this->business_name,
            'bio' => $this->bio,
            'city' => $this->city,
            'service_cities' => $this->service_cities,
            'specialties' => collect($this->specialties)->map(fn ($s) => ['key' => $s, 'label' => $labels[$s] ?? $s])->all(),
            'portfolio_tags' => $this->portfolio_tags ?? [],
            'starting_price' => self::money($this->starting_price_minor),
            'accepting_work' => $this->accepting_work,
            'verified' => $this->badgeActive(),
            'verification' => $this->verificationBadges(),
            // The selfie an OSAPS admin has checked. Shown only while the badges are active.
            'photo_url' => $this->profile_photo_path ? url('/'.$this->profile_photo_path) : null,
            'face_url' => $this->badgeActive() && $this->approvedSelfie() ? url('/api/v1/tailors/'.$this->slug.'/face') : null,
            'track_record' => [
                'completed_orders' => $record['completed_orders'],
                'on_time_rate' => $record['completed_orders'] > 0
                    ? round($record['on_time_orders'] / $record['completed_orders'], 2)
                    : null,
                'is_new' => $record['completed_orders'] < 3,
            ],
            'is_demo' => $this->is_demo,
            'match' => $this->when($this->hasExtra('match'), fn () => $this->extra('match')),
        ];
    }

    /** Badges show only when an admin has approved the matching document. */
    private function verificationBadges(): array
    {
        if (! $this->badgeActive()) {
            return ['status' => $this->verification_status, 'id_checked' => false, 'selfie_checked' => false, 'workshop_photo_checked' => false, 'cac_certificate_checked' => false];
        }
        $approved = $this->documents()->where('status', 'approved')->pluck('kind')->all();

        // Each badge says only what an OSAPS admin has looked at. None of them is an automated match.
        return [
            'status' => $this->verification_status,
            'id_checked' => $this->documents()->where('status', 'approved')->where('kind', 'id_document')
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()))->exists(),
            'selfie_checked' => in_array('selfie', $approved, true),
            'workshop_photo_checked' => in_array('workshop_photo', $approved, true),
            'cac_certificate_checked' => in_array('cac_certificate', $approved, true),
        ];
    }
}
