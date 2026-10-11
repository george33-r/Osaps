<?php

namespace Database\Factories;

use App\Models\JobRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobRequest> */
class JobRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'title' => 'Navy senator outfit for a wedding',
            'garment_category' => 'senator',
            'description' => 'Classic senator with subtle gold embroidery on the chest placket and cuffs.',
            'style_tags' => ['embroidered'],
            'mode' => 'budget',
            'budget_max_minor' => 3_000_000,
            'fabric_status' => 'own_fabric',
            'city' => 'Abuja',
            'needed_by' => now()->addDays(21)->toDateString(),
            'fitting_required' => true,
            'status' => 'open',
            'offers_close_at' => now()->addDays(7),
        ];
    }

    public function open(): static
    {
        return $this->state(['mode' => 'open', 'budget_max_minor' => null]);
    }
}
