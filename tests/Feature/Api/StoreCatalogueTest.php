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
}
