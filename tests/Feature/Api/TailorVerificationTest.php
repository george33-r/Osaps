<?php

namespace Tests\Feature\Api;

use App\Domain\Tailors\TailorVerification;
use App\Models\TailorProfile;
use App\Models\TailorVerificationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Tailor identity checks: consent, required documents, admin approval and when badges show. */
class TailorVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private TailorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tailor = User::factory()->tailor()->create();
        $this->profile = TailorProfile::factory()->create(['user_id' => $this->tailor->id, 'verification_status' => 'unverified']);
    }

    private function photo(string $name = 'x.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 400)->size(200);
    }

    public function test_face_and_id_need_consent_before_they_are_taken(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'selfie', 'file' => $this->photo(),
        ])->assertStatus(422)->assertJsonPath('code', 'consent_required');

        $this->assertDatabaseCount('tailor_verification_documents', 0);
    }

    public function test_review_needs_every_required_document(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'selfie', 'file' => $this->photo(), 'consent' => true,
        ])->assertCreated();

        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/submit')
            ->assertStatus(422)->assertJsonPath('code', 'verification_incomplete');
    }

    public function test_only_an_admin_can_verify_and_only_after_each_document_is_approved(): void
    {
        foreach (['id_document' => true, 'selfie' => true, 'workshop_photo' => false] as $kind => $consent) {
            $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
                'kind' => $kind, 'file' => $this->photo(), 'consent' => $consent,
            ])->assertCreated();
        }
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/submit')->assertOk()->assertJsonPath('data.status', 'pending');

        $admin = User::factory()->admin()->create();
        $this->actingAs($this->tailor)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/review", ['to' => 'verified'])->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/review", ['to' => 'verified'])
            ->assertStatus(422)->assertJsonPath('code', 'verification_incomplete');

        foreach (TailorVerificationDocument::query()->get() as $doc) {
            $this->actingAs($admin)->postJson("/api/v1/admin/tailor-documents/{$doc->id}/review", ['decision' => 'approved'])->assertOk();
        }
        $this->actingAs($admin)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/review", ['to' => 'verified'])->assertOk();

        $this->assertTrue($this->profile->fresh()->isVerified());
    }

    public function test_badges_and_face_show_only_for_approved_checks_on_a_verified_profile(): void
    {
        $selfie = TailorVerificationDocument::query()->create([
            'tailor_profile_id' => $this->profile->id, 'kind' => 'selfie', 'path' => 'tailor-verification/1/none.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1, 'status' => 'approved',
        ]);

        $this->getJson("/api/v1/tailors/{$this->profile->slug}")
            ->assertOk()->assertJsonPath('data.verification.selfie_checked', false)->assertJsonPath('data.face_url', null);

        $this->profile->forceFill(['verification_status' => 'verified'])->save();

        $this->getJson("/api/v1/tailors/{$this->profile->slug}")
            ->assertOk()->assertJsonPath('data.verification.selfie_checked', true)->assertJsonPath('data.verification.id_checked', false);
        $this->assertNotNull($selfie->id);
    }

    public function test_rejection_needs_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'workshop_photo', 'file' => $this->photo(),
        ])->assertCreated();
        $doc = TailorVerificationDocument::query()->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/admin/tailor-documents/{$doc->id}/review", ['decision' => 'rejected'])
            ->assertStatus(422)->assertJsonPath('code', 'verification_note_required');
    }
}
