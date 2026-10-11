<?php

namespace Database\Factories;

use App\Models\Fabric;
use App\Models\FabricVendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Fabric> */
class FabricFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'fabric_vendor_id' => FabricVendor::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'category' => 'ankara',
            'description' => fake()->sentence(12),
            'primary_color' => 'indigo',
            'colors' => ['indigo', 'gold'],
            'pattern' => 'geometric',
            'material' => '100% cotton wax print',
            'unit' => 'yard',
            'price_minor' => 450_000,
            'stock_quantity' => 60,
            'min_order_quantity' => 1,
            'status' => 'active',
        ];
    }
}
