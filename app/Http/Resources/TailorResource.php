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
            'verified' => $this->isVerified(),
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
}
