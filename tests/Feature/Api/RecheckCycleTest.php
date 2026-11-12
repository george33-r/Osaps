<?php

namespace Tests\Feature\Api;

use App\Models\FabricVendor;
use App\Models\TailorProfile;
use App\Models\User;
use App\Models\VendorVerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Six-week re-checks: overdue hides badges, uploads reopen, an approved cycle restarts the clock. */
class RecheckCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private TailorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tailor = User::factory()->tailor()->create();
        $this->profile = TailorProfile::factory()->create([
            'user_id' => $this->tailor->id, 'verification_status' => 'verified', 'verified_at' => now()->subWeeks(7),
            'recheck_due_at' => now()->subDay(),
        ]);
        $this->profile->documents()->create([
            'kind' => 'id_document', 'path' => 'x/id.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1,
            'status' => 'approved', 'reviewed_at' => now()->subWeeks(7),
        ]);
    }

    private function id(): UploadedFile
    {
        return UploadedFile::fake()->image('id.jpg', 400, 400)->size(200);
    }

    public function test_an_overdue_check_hides_the_badges_on_the_public_profile(): void
    {
        $this->getJson("/api/v1/tailors/{$this->profile->slug}")
            ->assertOk()->assertJsonPath('data.verification.id_checked', false)->assertJsonPath('data.verified', false);
    }

    public function test_a_check_that_is_not_due_does_not_reopen_uploads(): void
    {
        $this->profile->forceFill(['recheck_due_at' => now()->addWeeks(4)])->save();

        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'id_document', 'file' => $this->id(), 'consent' => true,
        ])->assertStatus(422)->assertJsonPath('code', 'already_verified');
    }

    public function test_an_overdue_check_reopens_uploads(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'id_document', 'file' => $this->id(), 'consent' => true,
        ])->assertCreated();
    }

    public function test_an_id_that_has_expired_is_refused(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'id_document', 'file' => $this->id(), 'consent' => true, 'expires_on' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_approving_the_new_id_completes_the_check_and_restarts_the_clock(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/tailor-verification/documents', [
            'kind' => 'id_document', 'file' => $this->id(), 'consent' => true,
        ])->assertCreated();
        $newId = $this->profile->documents()->where('status', 'submitted')->firstOrFail();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/admin/tailor-documents/{$newId->id}/review", ['decision' => 'approved'])->assertOk();

        $this->assertTrue($this->profile->fresh()->recheck_due_at->isFuture());
        $this->assertTrue($this->profile->fresh()->recheck_due_at->gt(now()->addWeeks(5)));
    }

    public function test_an_overdue_shop_can_upload_and_its_check_completes_when_both_documents_are_approved(): void
    {
        $owner = User::factory()->fabricVendor()->create();
        $shop = FabricVendor::factory()->create([
            'user_id' => $owner->id, 'verification_status' => 'verified', 'recheck_due_at' => now()->subDay(),
        ]);
        $admin = User::factory()->admin()->create();

        foreach (['cac_certificate' => UploadedFile::fake()->createWithContent('cac.pdf', "%PDF-1.4\n%%EOF\n"), 'id_document' => $this->id()] as $kind => $file) {
            $this->actingAs($owner)->postJson('/api/v1/me/vendor-documents', ['kind' => $kind, 'file' => $file, 'consent' => true])->assertCreated();
        }
        foreach (VendorVerificationDocument::query()->where('fabric_vendor_id', $shop->id)->pluck('id') as $docId) {
            $this->actingAs($admin)->postJson("/api/v1/admin/vendor-documents/{$docId}/review", ['decision' => 'approved'])->assertOk();
        }

        $this->assertTrue($shop->fresh()->recheck_due_at->isFuture());
    }
}
