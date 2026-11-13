<?php

namespace Database\Factories;

use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TailorProfile> */
class TailorProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Atelier';

        return [
            'user_id' => User::factory()->tailor(),
            'business_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'bio' => fake()->sentence(14),
            'city' => 'Abuja',
            'service_cities' => ['Abuja'],
            'specialties' => ['senator', 'agbada'],
            'portfolio_tags' => ['embroidered'],
            'weekly_capacity' => 6,
            'starting_price_minor' => 2_000_000,
            'accepting_work' => true,
        ];
    }

    /** Profiles get a photo unless a test clears it, because a photo is required to make offers. */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\TailorProfile $profile) {
            if ($profile->profile_photo_path === null && ! $profile->is_demo) {
                $profile->forceFill(['profile_photo_path' => 'uploads/tailors/test.jpg'])->save();
            }
        });
    }
}
