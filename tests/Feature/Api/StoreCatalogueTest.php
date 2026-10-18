<?php

namespace Tests\Feature\Api;

use App\Models\Fabric;
use App\Models\FabricMedia;
use Database\Seeders\StoreCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_catalogue_loads_once_and_keeps_owner_edits(): void
    {
        $seeder = new StoreCatalogueSeeder;
        $seeder->run();
        $this->assertSame(17, Fabric::query()->count());
        $this->assertSame(1_600_000, Fabric::query()->where('slug', 'red-butterfly-ankara')->value('price_minor'));
        $this->getJson('/api/v1/fabrics/red-butterfly-ankara')->assertOk()->assertJsonPath('data.price.amount_minor', 1_600_000);
        $this->assertSame(17, FabricMedia::query()->count());

        Fabric::query()->where('slug', 'red-butterfly-ankara')->update(['price_minor' => 1_500_000]);
        (new StoreCatalogueSeeder)->run();
        $this->assertSame(17, Fabric::query()->count());
        $this->assertSame(1_500_000, Fabric::query()->where('slug', 'red-butterfly-ankara')->value('price_minor'));
        $this->assertSame(17, FabricMedia::query()->count());
    }

    public function test_installer_creates_the_store_vendor_with_its_owner_account(): void
    {
        (new StoreCatalogueSeeder)->run();
        $vendor = \App\Models\FabricVendor::query()->firstOrFail();
        $this->assertNotNull($vendor->user_id);
        $this->assertSame('fabric_vendor', \App\Models\User::query()->findOrFail($vendor->user_id)->role);
    }

    public function test_installer_relists_the_store_after_its_bank_details_sent_it_back_for_a_check(): void
    {
        (new StoreCatalogueSeeder)->run();
        \App\Models\FabricVendor::query()->update(['verification_status' => 'pending']);
        $this->getJson('/api/v1/fabrics')->assertJsonPath('meta.total', 0);
        $seeder = new StoreCatalogueSeeder;
        $seeder->run();
        $this->getJson('/api/v1/fabrics')->assertJsonPath('meta.total', 17);
        $this->assertStringContainsString('showing on the site now: 17', implode("\n", $seeder->report));
    }
}
