<?php

namespace Database\Factories;

use App\Models\FabricVendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FabricVendor> */
class FabricVendorFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Textiles';

        return [
            'user_id' => User::factory()->fabricVendor(),
            'business_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'city' => 'Lagos',
            'market' => 'Balogun Market',
            'phone' => '0803 000 0000',
            'bank_name' => 'Test Bank',
            'bank_account_name' => $name,
            'bank_account_number' => '0123456789',
            // Most tests buy from a shop OSAPS has already checked.
            'verification_status' => 'verified',
            'verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['verification_status' => 'unverified', 'verified_at' => null]);
    }
}
