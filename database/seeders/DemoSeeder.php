<?php

namespace Database\Seeders;

use App\Models\Fabric;
use App\Models\FabricVendor;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL DEMONSTRATION DATA. Every record is flagged is_demo = true.
 *
 * Honesty rules applied here:
 *  - No reviews, ratings, completed orders or earnings are invented.
 *  - No vendor is marked "verified" — verification only happens through admin review.
 *  - Prices are illustrative placeholders, not market research.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must never run in production.');
        }

        $password = 'demo-pass-123';

        $this->demoUser(['email' => 'customer@demo.osapa.test'], [
            'name' => 'Amaka Demo', 'password' => $password, 'city' => 'Abuja', 'role' => 'customer',
        ]);
        $this->demoUser(['email' => 'admin@demo.osapa.test'], [
            'name' => 'Admin Demo', 'password' => $password, 'city' => 'Abuja', 'role' => 'admin',
        ]);

        $this->seedTailors($password);
        $this->seedFabrics($password);

        $this->command?->info("Demo accounts (password: {$password}): customer@demo.osapa.test, tailor1@demo.osapa.test, admin@demo.osapa.test");
    }

    /** Roles are not mass-assignable, so demo users are written with forceFill. */
    private function demoUser(array $key, array $attributes): User
    {
        $user = User::query()->firstOrNew($key);
        $user->forceFill($key + $attributes)->save();

        return $user;
    }

    private function seedTailors(string $password): void
    {
        $tailors = [
            ['Ọ̀ṣọ́ Atelier', 'Abuja', ['Abuja'], ['senator', 'agbada', 'kaftan'], ['embroidered', 'minimal'], 2_500_000,
                'Menswear studio focused on clean senator cuts and hand-finished embroidery.'],
            ['Threadline by Zainab', 'Abuja', ['Abuja', 'Kaduna'], ['gown', 'skirt_blouse', 'aso_ebi'], ['beaded', 'off-shoulder'], 3_000_000,
                'Occasion womenswear: structured gowns and coordinated aṣọ-ẹbí groups.'],
            ['Kúnlé Tailoring House', 'Lagos', ['Lagos', 'Ibadan'], ['agbada', 'buba_sokoto', 'suit'], ['embroidered', 'classic'], 4_500_000,
                'Traditional ceremonial wear and tailored suits.'],
            ['Adaeze Couture', 'Lagos', ['Lagos'], ['bridal', 'gown'], ['lace', 'beaded'], 12_000_000,
                'Bridal and evening couture with lace appliqué.'],
            ['Northern Needle', 'Kano', ['Kano', 'Abuja'], ['kaftan', 'senator', 'kids'], ['minimal'], 1_500_000,
                'Everyday kaftans and senators, family and children\'s orders welcome.'],
            ['Iro & Co.', 'Ibadan', ['Ibadan', 'Lagos'], ['iro_buba', 'aso_ebi', 'alteration'], ['adire', 'classic'], 1_800_000,
                'Iro and buba in aṣọ-òkè and adire; quick alterations.'],
        ];

        foreach ($tailors as $i => [$name, $city, $cities, $specialties, $tags, $from, $bio]) {
            $user = $this->demoUser(['email' => 'tailor'.($i + 1).'@demo.osapa.test'], [
                'name' => $name, 'password' => $password, 'city' => $city, 'role' => 'tailor',
            ]);
            $profile = TailorProfile::query()->firstOrNew(['user_id' => $user->id]);
            $profile->forceFill([
                'business_name' => $name,
                'slug' => Str::slug(Str::ascii($name)),
                'bio' => $bio,
                'city' => $city,
                'service_cities' => $cities,
                'specialties' => $specialties,
                'portfolio_tags' => $tags,
                'weekly_capacity' => 6,
                'starting_price_minor' => $from,
                'accepting_work' => true,
                'verification_status' => 'unverified',
                'is_demo' => true,
            ])->save();
        }
    }

    private function seedFabrics(string $password): void
    {
        $vendors = [
            ['Balogun Bolts', 'Lagos', 'Balogun Market'],
            ['Wuse Textiles', 'Abuja', 'Wuse Market'],
            ['Kantin Kwari Cloth', 'Kano', 'Kantin Kwari Market'],
        ];
        $vendorModels = [];
        foreach ($vendors as $i => [$name, $city, $market]) {
            $user = $this->demoUser(['email' => 'vendor'.($i + 1).'@demo.osapa.test'], [
                'name' => $name, 'password' => $password, 'city' => $city, 'role' => 'fabric_vendor',
            ]);
            $vendor = FabricVendor::query()->firstOrNew(['user_id' => $user->id]);
            $vendor->forceFill([
                'business_name' => $name, 'slug' => Str::slug($name), 'city' => $city, 'market' => $market, 'is_demo' => true,
            ])->save();
            $vendorModels[] = $vendor;
        }

        // [name, category, primary, colors, pattern, material, unit, price ₦, stock, vendor idx]
        $fabrics = [
            ['Indigo Sunburst Ankara', 'ankara', 'indigo', ['indigo', 'gold', 'cream'], 'sunburst', 'Cotton wax print', 'yard', 4_500, 120, 0],
            ['Terracotta Kola Ankara', 'ankara', 'terracotta', ['terracotta', 'cream', 'black'], 'circles', 'Cotton wax print', 'yard', 3_800, 80, 0],
            ['Emerald Lattice Ankara', 'ankara', 'emerald', ['emerald', 'gold', 'black'], 'lattice', 'Cotton wax print', 'yard', 4_200, 6, 0],
            ['Midnight Adire Eleko', 'adire', 'indigo', ['indigo', 'white'], 'resist', 'Hand-dyed cotton', 'yard', 6_000, 40, 1],
            ['Oniko Tie-Dye Adire', 'adire', 'royal_blue', ['royal_blue', 'white'], 'tie_dye', 'Hand-dyed cotton', 'yard', 5_500, 25, 1],
            ['Burgundy Sanyan Aṣọ-òkè', 'aso_oke', 'burgundy', ['burgundy', 'gold'], 'stripes', 'Hand-woven cotton & silk', 'bundle', 85_000, 12, 1],
            ['Gold Alaari Aṣọ-òkè', 'aso_oke', 'gold', ['gold', 'cream'], 'stripes', 'Hand-woven metallic yarn', 'bundle', 95_000, 0, 1],
            ['Ivory Guipure Lace', 'lace', 'cream', ['cream'], 'floral', 'Polyester guipure', 'yard', 18_000, 30, 0],
            ['Champagne Beaded Lace', 'lace', 'gold', ['gold', 'cream'], 'floral', 'Tulle with bead work', 'yard', 32_000, 15, 0],
            ['Navy Cashmere Senator', 'senator', 'navy', ['navy'], 'plain', 'Cashmere blend', 'yard', 7_500, 200, 2],
            ['Charcoal Senator Twill', 'senator', 'black', ['black'], 'twill', 'Polyester-wool blend', 'yard', 6_200, 150, 2],
            ['Cream Senator Linen', 'senator', 'cream', ['cream'], 'plain', 'Linen blend', 'yard', 6_800, 90, 2],
            ['Navy Super 120s Suiting', 'suiting', 'navy', ['navy'], 'plain', 'Wool', 'metre', 22_000, 45, 0],
            ['Wine Silk George', 'george', 'burgundy', ['burgundy', 'gold'], 'paisley', 'Silk george', 'piece', 65_000, 8, 0],
            ['Emerald Damask Brocade', 'damask', 'emerald', ['emerald'], 'jacquard', 'Cotton damask', 'yard', 9_500, 70, 2],
            ['Royal Purple Ankara', 'ankara', 'purple', ['purple', 'gold', 'orange'], 'feathers', 'Cotton wax print', 'yard', 4_000, 55, 1],
        ];

        foreach ($fabrics as [$name, $cat, $primary, $colors, $pattern, $material, $unit, $naira, $stock, $v]) {
            $fabric = Fabric::query()->firstOrNew(['slug' => Str::slug(Str::ascii($name))]);
            $fabric->forceFill([
                'fabric_vendor_id' => $vendorModels[$v]->id,
                'name' => $name,
                'category' => $cat,
                'description' => "{$material}. Demonstration listing — illustrative price and stock.",
                'primary_color' => $primary,
                'colors' => $colors,
                'pattern' => $pattern,
                'material' => $material,
                'unit' => $unit,
                'price_minor' => $naira * 100,
                'stock_quantity' => $stock,
                'min_order_quantity' => $unit === 'yard' ? 2 : 1,
                'status' => 'active',
                'is_demo' => true,
            ])->save();
        }
    }
}
