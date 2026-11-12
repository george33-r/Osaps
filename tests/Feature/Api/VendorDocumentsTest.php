<?php

namespace Tests\Feature\Api;

use App\Domain\Support\DomainRuleViolation;
use App\Models\FabricVendor;
use App\Models\User;
use App\Models\VendorVerificationDocument;
use App\Services\TrustService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Shop verification documents: CAC certificate and owner's ID, consent, review gates and admin-only decisions. */
class VendorDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private FabricVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->fabricVendor()->create();
        $this->vendor = FabricVendor::factory()->unverified()->create(['user_id' => $this->owner->id]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cac.pdf', "%PDF-1.4\n%%EOF\n");
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('id.jpg', 400, 400)->size(200);
    }

    public function test_the_owners_id_needs_consent_before_it_is_taken(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/me/vendor-documents', [
            'kind' => 'id_document', 'file' => $this->photo(),
        ])->assertStatus(422)->assertJsonPath('code', 'consent_required');

        $this->assertDatabaseCount('vendor_verification_documents', 0);
    }

    public function test_a_cac_certificate_can_be_uploaded_as_a_pdf(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/me/vendor-documents', [
            'kind' => 'cac_certificate', 'file' => $this->pdf(),
        ])->assertCreated()->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('vendor_verification_documents', ['fabric_vendor_id' => $this->vendor->id, 'mime' => 'application/pdf']);
    }

    public function test_review_cannot_be_asked_for_until_both_documents_are_in(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/me/vendor-documents', [
            'kind' => 'cac_certificate', 'file' => $this->pdf(),
        ])->assertCreated();

        $this->expectException(DomainRuleViolation::class);
        app(TrustService::class)->requestReview($this->vendor->fresh());
    }

    public function test_uploads_are_closed_while_a_review_is_pending(): void
    {
        $this->vendor->forceFill(['verification_status' => 'pending'])->save();

        $this->actingAs($this->owner)->postJson('/api/v1/me/vendor-documents', [
            'kind' => 'cac_certificate', 'file' => $this->pdf(),
        ])->assertStatus(422)->assertJsonPath('code', 'review_in_progress');
    }

    public function test_a_shop_cannot_be_verified_until_every_document_is_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $this->vendor->forceFill(['verification_status' => 'pending', 'verified_at' => null])->save();
        $cac = VendorVerificationDocument::query()->create([
            'fabric_vendor_id' => $this->vendor->id, 'kind' => 'cac_certificate', 'path' => 'x/cac.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'approved',
        ]);
        VendorVerificationDocument::query()->create([
            'fabric_vendor_id' => $this->vendor->id, 'kind' => 'id_document', 'path' => 'x/id.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 10, 'status' => 'submitted',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/admin/vendors/{$this->vendor->id}/review", ['to' => 'verified'])
            ->assertStatus(422)->assertJsonPath('code', 'documents_not_approved');

        $cac->refresh();
        $id = VendorVerificationDocument::query()->where('fabric_vendor_id', $this->vendor->id)->where('kind', 'id_document')->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-documents/{$id->id}/review", ['decision' => 'approved'])->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/admin/vendors/{$this->vendor->id}/review", ['to' => 'verified'])->assertOk();
    }

    public function test_a_rejected_document_needs_a_reason_the_owner_can_act_on(): void
    {
        $admin = User::factory()->admin()->create();
        $doc = VendorVerificationDocument::query()->create([
            'fabric_vendor_id' => $this->vendor->id, 'kind' => 'cac_certificate', 'path' => 'x/cac.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'submitted',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-documents/{$doc->id}/review", ['decision' => 'rejected'])
            ->assertStatus(422)->assertJsonPath('code', 'verification_note_required');
    }

    public function test_only_an_admin_can_decide_a_document(): void
    {
        $doc = VendorVerificationDocument::query()->create([
            'fabric_vendor_id' => $this->vendor->id, 'kind' => 'cac_certificate', 'path' => 'x/cac.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'submitted',
        ]);

        $this->actingAs($this->owner)->postJson("/api/v1/admin/vendor-documents/{$doc->id}/review", ['decision' => 'approved'])
            ->assertForbidden();
    }
}
