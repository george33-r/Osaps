<?php

namespace Tests\Feature\Api;

use App\Models\Story;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Saved stories, tailor of the week, ask-a-tailor, and moments. */
class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private TailorProfile $profile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->profile = TailorProfile::factory()->create(['business_name' => 'Ife Threads']);
        $this->profile->forceFill(['verification_status' => 'verified', 'verified_at' => now(), 'recheck_due_at' => now()->addWeeks(6)])->save();
        $this->admin = User::factory()->admin()->create();
    }

    private function publishedStory(): int
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/admin/stories', [
            'title' => 'Cleaning your lace', 'body' => 'Use cool water and hang it in shade to keep the colour.',
        ])->assertCreated()->json('data.id');

        return (int) $id;
    }

    public function test_customer_saves_a_live_story_and_can_unsave_it(): void
    {
        $id = $this->publishedStory();
        $this->actingAs($this->customer)->postJson("/api/v1/stories/{$id}/save")->assertCreated();
        $this->actingAs($this->customer)->getJson('/api/v1/me/saved-stories')->assertJsonCount(1, 'data');
        $this->actingAs($this->customer)->getJson('/api/v1/me/saved-story-ids')->assertJsonPath('data.0', $id);

        $this->actingAs($this->customer)->deleteJson("/api/v1/stories/{$id}/save")->assertOk();
        $this->actingAs($this->customer)->getJson('/api/v1/me/saved-stories')->assertJsonCount(0, 'data');
    }

    public function test_a_waiting_story_cannot_be_saved(): void
    {
        $id = $this->actingAs($this->profile->user)->postJson('/api/v1/me/stories', [
            'title' => 'Waiting story', 'body' => 'This one has not been checked by OSAPS yet.',
        ])->json('data.id');
        $this->actingAs($this->customer)->postJson("/api/v1/stories/{$id}/save")->assertNotFound();
    }

    public function test_tailor_of_week_is_empty_until_someone_qualifies_and_admin_can_choose(): void
    {
        $this->getJson('/api/v1/tailor-of-week')->assertOk()->assertJsonPath('data', null);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/tailor-of-week', [
            'tailor_profile_id' => $this->profile->id, 'reason' => 'Consistent work and fast replies this week.',
        ])->assertOk();

        $this->getJson('/api/v1/tailor-of-week')->assertOk()
            ->assertJsonPath('data.source_label', 'Chosen by the OSAPS team')
            ->assertJsonPath('data.tailor.name', 'Ife Threads');
    }

    public function test_tailor_of_week_choice_needs_a_reason_and_a_verified_tailor(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tailor-of-week', [
            'tailor_profile_id' => $this->profile->id, 'reason' => 'short',
        ])->assertStatus(422);

        $unverified = TailorProfile::factory()->create();
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tailor-of-week', [
            'tailor_profile_id' => $unverified->id, 'reason' => 'A reason that is long enough.',
        ])->assertStatus(422)->assertJsonPath('code', 'not_verified');
    }

    public function test_question_moves_from_check_to_open_to_answer_to_a_tip(): void
    {
        $id = $this->actingAs($this->customer)->postJson('/api/v1/questions', [
            'question' => 'How long does a senator usually take to make?',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/questions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'open');

        $outsider = TailorProfile::factory()->create()->user; // not verified
        $this->actingAs($outsider)->postJson("/api/v1/questions/{$id}/answer", ['answer' => 'About two weeks with fittings included.'])
            ->assertStatus(422)->assertJsonPath('code', 'verification_required');

        $this->actingAs($this->profile->user)->postJson("/api/v1/questions/{$id}/answer", ['answer' => 'About two weeks, including one fitting.'])
            ->assertOk()->assertJsonPath('data.status', 'answered');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/questions/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/questions')->assertOk()->assertJsonPath('data.0.answered_by.name', 'Ife Threads');
        $this->getJson('/api/v1/stories')->assertOk()
            ->assertJsonPath('data.0.kind', 'tip')
            ->assertJsonPath('data.0.byline', 'Answered by Ife Threads');
    }

    public function test_customer_cannot_ask_more_than_five_questions_waiting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->customer)->postJson('/api/v1/questions', ['question' => "Question number {$i} about fabric care."])->assertCreated();
        }
        $this->actingAs($this->customer)->postJson('/api/v1/questions', ['question' => 'One more question about fabric care.'])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_pending');
    }

    public function test_moment_shows_straight_away_in_a_circle_for_everyone(): void
    {
        $id = $this->actingAs($this->profile->user)->postJson('/api/v1/moments', [
            'media' => UploadedFile::fake()->image('outfit.jpg', 400, 500), 'caption' => 'Fresh from the workshop',
        ])->assertCreated()->assertJsonPath('data.status', 'published')->json('data.id');

        $this->getJson('/api/v1/moments')->assertOk()
            ->assertJsonPath('data.0.label', 'Ife Threads')
            ->assertJsonPath('data.0.moments.0.type', 'photo');
        $this->assertStringNotContainsString($this->profile->user->email, json_encode($this->getJson('/api/v1/moments')->json()));
    }

    public function test_hidden_moment_needs_a_reason_and_is_not_shown(): void
    {
        $id = $this->actingAs($this->customer)->postJson('/api/v1/moments', [
            'media' => UploadedFile::fake()->image('a.jpg'),
        ])->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/moments/{$id}/status", ['status' => 'hidden'])
            ->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/moments/{$id}/status", ['status' => 'hidden', 'note' => 'Shows someone without consent.'])->assertOk();

        $this->getJson('/api/v1/moments')->assertJsonCount(0, 'data');
        $this->actingAs($this->customer)->getJson('/api/v1/me/moments')
            ->assertJsonPath('data.0.hidden_reason', 'Shows someone without consent.');
    }

    public function test_only_the_owner_or_an_admin_can_delete_a_moment(): void
    {
        $id = $this->actingAs($this->customer)->postJson('/api/v1/moments', ['media' => UploadedFile::fake()->image('a.jpg')])->json('data.id');
        $this->actingAs($this->profile->user)->deleteJson("/api/v1/moments/{$id}")->assertForbidden();
        $this->actingAs($this->customer)->deleteJson("/api/v1/moments/{$id}")->assertOk();
    }
}
