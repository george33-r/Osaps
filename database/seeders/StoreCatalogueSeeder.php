<?php

namespace Database\Seeders;

use App\Models\Fabric;
use App\Models\FabricMedia;
use App\Models\FabricVendor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The OSAPS store's own fabrics (database/catalogue/osaps-store.json), safe to run on the live site.
 *
 *  - Creates the store's vendor account once (STORE_EMAIL, default store@<APP_URL host>), verified,
 *    and prints its first password once, on the installer page only.
 *  - Adds each fabric once, keyed by slug. Running it again never changes a price, stock or
 *    status the owner has since edited in the vendor hub; it only fills in anything missing.
 *  - Copies each real photo into public uploads and attaches it as the listing's first photo.
 */
class StoreCatalogueSeeder extends Seeder
{
    /** @var list<string> */
    public array $report = [];

    public function run(): void
    {
        $file = database_path('catalogue/osaps-store.json');
        $catalogue = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($catalogue) {
            $vendor = $this->storeVendor((string) ($catalogue['store']['business_name'] ?? 'OSAPS Store'));
            $added = 0;
            foreach ($catalogue['fabrics'] as $row) {
                $fabric = Fabric::query()->where('slug', $row['slug'])->first();
                if ($fabric === null) {
                    $fabric = new Fabric;
                    $fabric->forceFill([
                        'fabric_vendor_id' => $vendor->id,
                        'slug' => $row['slug'],
                        'name' => $row['name'],
                        'category' => $row['category'],
                        'description' => $row['note'].' Real photo from the OSAPS store. Sold per yard.',
                        'primary_color' => $row['primary_color'],
                        'colors' => $row['colors'],
                        'pattern' => $row['pattern'],
                        'material' => null,
                        'unit' => 'yard',
                        'price_minor' => (int) $row['price_naira'] * 100,
                        'stock_quantity' => (int) ($catalogue['default_stock'] ?? 20),
                        'min_order_quantity' => 1,
                        'status' => 'active',
                        'is_demo' => false,
                    ])->save();
                    $added++;
                }
                $this->attachPhoto($fabric, $row['slug']);
            }
            $this->report[] = "Store catalogue: {$added} new fabric(s) added, ".(count($catalogue['fabrics']) - $added).' already there (left as they are).';
        });
    }

    private function storeVendor(string $name): FabricVendor
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'shoposaps.com';
        $email = strtolower((string) (env('STORE_EMAIL') ?: 'store@'.preg_replace('/^www\./', '', $host)));
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $password = Str::password(16, symbols: false);
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password, 'city' => null]);
            $user->role = User::ROLE_FABRIC_VENDOR;
            $user->save();
            $this->report[] = "Store account created. Sign in as {$email} with password {$password} — it is shown only now. Change it after signing in (Forgot password works too once email is set up).";
        }
        $vendor = FabricVendor::query()->firstOrNew(['user_id' => $user->id]);
        if (! $vendor->exists) {
            $vendor->forceFill([
                'business_name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                'city' => 'Nigeria',
                // OSAPS's own shop: verified by the owner who runs this installer.
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verification_note' => 'OSAPS\'s own store, created by the installer.',
            ])->save();
            $this->report[] = "Shop \"{$name}\" created and verified. Add its phone, market and bank details in /vendor → Shop & payouts.";
        }

        return $vendor;
    }

    private function attachPhoto(Fabric $fabric, string $slug): void
    {
        if (FabricMedia::query()->where('fabric_id', $fabric->id)->where('kind', 'photo')->exists()) {
            return;
        }
        $src = database_path("catalogue/photos/{$slug}.webp");
        if (! is_file($src)) {
            return;
        }
        $dir = public_path("uploads/fabrics/{$fabric->id}");
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->report[] = "Could not create {$dir}: photos not copied.";

            return;
        }
        $name = Str::lower(Str::random(20)).'.webp';
        if (! @copy($src, "{$dir}/{$name}")) {
            $this->report[] = "Could not copy the photo for {$fabric->name}.";

            return;
        }
        $media = new FabricMedia(['kind' => 'photo', 'path' => "fabrics/{$fabric->id}/{$name}", 'mime' => 'image/webp', 'size_bytes' => filesize($src), 'sort' => 0]);
        $media->fabric_id = $fabric->id;
        $media->save();
    }
}
