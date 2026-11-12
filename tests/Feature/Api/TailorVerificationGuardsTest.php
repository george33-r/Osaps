<?php

namespace Tests\Feature\Api;

use App\Models\TailorProfile;
use App\Models\User;
use App\Models\TailorVerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** A verified tailor cannot change who they are without a new check, and uploads wait for the review to finish. */
class TailorVerificationGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private TailorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tailor = User::factory()->tailor()->create();
        $this->profile = TailorProfile::factory()->create([
            'user_id' => $this->tailor->id, 'business_name' => 'Ada Stitches', 'city' => 'Abuja', 'verification_status' => 'verified',
        ]);
    }

    /** The fields the profile form sends, with the given changes. */
    private function form(array $changes = []): array
    {
        return array_merge([
            'business_name' => 'Ada Stitches', 'city' => 'Abuja', 'service_cities' => ['Abuja'],
            'specialties' => [array_key_first(config('marketplace.garment_categories'))], 'weekly_capacity' => 6,
        ], $changes);
    }

    public function test_changing_the_business_name_sends_a_verified_tailor_back_for_review(): void
    {
        $this->actingAs($this->tailor)->putJson('/api/v1/me/tailor-profile', $this->form(['business_name' => 'Ada Couture']))
            ->assertOk()->assertJsonPath('data.verification.status', 'pending');

        $this->assertSame('pending', $this->profile->fresh()->verification_status);
    }

    public function test_changing_the_city_sends_a_verified_tailor_back_for_review(): void
    {
        $this->actingAs($this->tailor)->putJson('/api/v1/me/tailor-profile', $this->form(['city' => 'Lagos']))
            ->assertOk()->assertJsonPath('data.verification.status', 'pending');
    }

    public function test_changing_other_details_keeps_the_verified_badge(): void
    {
        $this->actingAs($this->tailor)->putJson('/api/v1/me/tailor-profile', $this->form(['weekly_capacity' => 12, 'bio' => 'Agbada and aso-oke']))
            ->assertOk()->assertJsonPath('data.verification.status', 'verified');

        $this->assertSame('verified', $this->profile->fresh()->verification_status);
    }

    public function test_documents_cannot_be_replaced_while_a_review_is_pending(): void
    {
        $this->profile->forceFill(['verification_status' => 'pending'])->save();

        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'workshop_photo', 'file' => UploadedFile::fake()->image('w.jpg', 400, 400)->size(200),
        ])->assertStatus(422)->assertJsonPath('code', 'review_in_progress');

        $this->assertDatabaseCount('tailor_verification_documents', 0);
    }

    public function test_badges_name_what_was_checked_not_an_automated_match(): void
    {
        TailorVerificationDocument::query()->create([
            'tailor_profile_id' => $this->profile->id, 'kind' => 'selfie', 'path' => 'x/selfie.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1, 'status' => 'approved',
        ]);

        $this->getJson("/api/v1/tailors/{$this->profile->slug}")
            ->assertOk()
            ->assertJsonPath('data.verification.selfie_checked', true)
            ->assertJsonMissingPath('data.verification.face_matched');
    }
}
