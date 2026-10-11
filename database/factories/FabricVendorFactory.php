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
        ];
    }
}
