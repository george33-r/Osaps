<?php

namespace Tests\Feature\Api;

use App\Models\TailorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TailorPhotoRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tailors_without_a_photo_are_hidden_from_the_directory(): void
    {
        TailorProfile::factory()->create(['business_name' => 'Shown Maker']);
        $hidden = TailorProfile::factory()->create(['business_name' => 'Hidden Maker']);
        $hidden->forceFill(['profile_photo_path' => null])->save();

        $names = collect($this->getJson('/api/v1/tailors')->assertOk()->json('data'))->pluck('business_name');

        $this->assertTrue($names->contains('Shown Maker'));
        $this->assertFalse($names->contains('Hidden Maker'));
    }
}
