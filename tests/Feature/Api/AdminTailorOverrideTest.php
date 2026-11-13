<?php

namespace Tests\Feature\Api;

use App\Domain\Tailors\TailorVerification;
use App\Mail\AdminBroadcast;
use App\Models\AdminAction;
use App\Models\TailorProfile;
use App\Models\TailorVerificationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The admin tailors list, the documents-uploaded override (with a logged reason) and bulk mail. */
class AdminTailorOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tailor;

    private TailorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->tailor = User::factory()->tailor()->create();
        $this->profile = TailorProfile::factory()->create([
            'user_id' => $this->tailor->id, 'verification_status' => TailorVerification::PENDING,
        ]);
    }

    private function uploadRequired(bool $approved = false): void
    {
        foreach (TailorVerification::REQUIRED as $kind) {
            TailorVerificationDocument::query()->create([
                'tailor_profile_id' => $this->profile->id, 'kind' => $kind, 'path' => 'private/x.jpg',
                'mime' => 'image/jpeg', 'size_bytes' => 100, 'status' => $approved ? 'approved' : 'submitted',
            ]);
        }
    }

    public function test_override_needs_every_required_document_uploaded(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/override-verify", [
            'note' => 'Checked the ID by phone with the tailor.',
        ])->assertStatus(422)->assertJsonPath('code', 'verification_incomplete');

        $this->assertSame(TailorVerification::PENDING, $this->profile->fresh()->verification_status);
    }

    public function test_override_needs_a_reason_and_logs_it(): void
    {
        $this->uploadRequired();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/override-verify", [
            'note' => 'short',
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/override-verify", [
            'note' => 'Documents uploaded Monday, review missed; checked again today.',
        ])->assertOk()->assertJsonPath('data.status', TailorVerification::VERIFIED);

        $this->assertDatabaseHas('admin_actions', ['action' => 'tailor_verified_override', 'admin_id' => $this->admin->id]);
    }

    public function test_tailor_cannot_use_the_override(): void
    {
        $this->uploadRequired();

        $this->actingAs($this->tailor)->postJson("/api/v1/admin/tailor-profiles/{$this->profile->id}/override-verify", [
            'note' => 'I am verified, trust me.',
        ])->assertStatus(403);
    }

    public function test_directory_lists_tailors_without_a_profile_and_their_photo_state(): void
    {
        $noProfile = User::factory()->tailor()->create();

        $rows = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/tailors')->assertOk()->json('data'));

        $this->assertSame('no_profile', $rows->firstWhere('user_id', $noProfile->id)['status']);
        $this->assertFalse($rows->firstWhere('user_id', $this->tailor->id)['has_photo']);
    }

    public function test_bulk_mail_queues_one_message_per_selected_tailor_and_logs_it(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/tailors/bulk-mail', [
            'user_ids' => [$this->tailor->id],
            'subject' => 'Add your profile photo',
            'body' => 'Please add a clear photo of yourself or your workshop to your profile.',
        ])->assertStatus(202)->assertJsonPath('data.queued', 1);

        Mail::assertQueued(AdminBroadcast::class, 1);
        $this->assertSame(1, AdminAction::query()->where('action', 'tailor_bulk_mail')->first()->data['recipients']);
    }

    public function test_bulk_mail_refuses_non_tailor_accounts(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/tailors/bulk-mail', [
            'user_ids' => [$customer->id],
            'subject' => 'Hello there',
            'body' => 'This should not be sent to a customer account.',
        ])->assertStatus(422);
    }
}
