<?php

namespace Tests\Feature\Api;

use App\Models\Story;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Home page stories: tailors wait for approval, admins publish directly, public output keeps private details out. */
class StoryTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private TailorProfile $profile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = TailorProfile::factory()->create(['business_name' => 'Tunde Stitch House']);
        $this->tailor = $this->profile->user;
        $this->admin = User::factory()->admin()->create();
    }

    private function story(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Agbada for a naming ceremony',
            'body' => 'We cut and stitched this in four days, with the embroidery done by hand.',
        ], $overrides);
    }

    public function test_tailor_story_waits_for_approval_and_is_not_public(): void
    {
        $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.kind', 'story');

        $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_publishes_and_the_public_list_hides_contact_details(): void
    {
        $id = $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/stories/{$id}/status", ['status' => 'published'])->assertOk();

        $body = $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.byline', 'By Tunde Stitch House')->json();
        $raw = json_encode($body);
        $this->assertStringNotContainsString($this->tailor->email, $raw);
        $this->assertStringNotContainsString('author_id', $raw);
    }

    public function test_editing_a_published_story_sends_it_back_for_a_check(): void
    {
        $id = $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/stories/{$id}/status", ['status' => 'published'])->assertOk();

        $this->actingAs($this->tailor)->postJson("/api/v1/me/stories/{$id}", $this->story(['title' => 'Agbada, hand embroidered']))
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/stories')->assertJsonCount(0, 'data');
    }

    public function test_tailor_cannot_feature_a_spotlight_or_attach_another_profile(): void
    {
        $other = TailorProfile::factory()->create();
        $res = $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story([
            'kind' => 'spotlight', 'tailor_profile_id' => $other->id,
        ]))->assertCreated();

        $this->assertSame('story', $res->json('data.kind'));
        $this->assertDatabaseHas('stories', ['id' => $res->json('data.id'), 'tailor_profile_id' => $this->profile->id]);
    }

    public function test_only_internal_links_are_allowed(): void
    {
        foreach (['https://example.com', '//example.com', 'javascript:alert(1)'] as $bad) {
            $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story(['link_url' => $bad]))->assertStatus(422);
        }
        $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story(['link_url' => '/tailors/'.$this->profile->slug]))->assertCreated();
    }

    public function test_hiding_needs_a_reason_and_the_tailor_sees_it(): void
    {
        $id = $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/stories/{$id}/status", ['status' => 'hidden'])
            ->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/stories/{$id}/status", ['status' => 'hidden', 'note' => 'Quotes a price we cannot confirm.'])->assertOk();

        $this->actingAs($this->tailor)->getJson('/api/v1/me/stories')
            ->assertJsonPath('data.0.status', 'hidden')
            ->assertJsonPath('data.0.hidden_reason', 'Quotes a price we cannot confirm.');
    }

    public function test_waiting_stories_are_capped(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story(['title' => "Story number {$i}"]))->assertCreated();
        }
        $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())
            ->assertStatus(422)->assertJsonPath('code', 'too_many_pending');
    }

    public function test_admin_spotlight_is_published_at_once_and_features_the_tailor(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/stories', $this->story([
            'kind' => 'spotlight', 'tailor_profile_id' => $this->profile->id, 'title' => 'Tailor of the week',
        ]))->assertCreated()->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/stories')->assertJsonPath('data.0.kind', 'spotlight')
            ->assertJsonPath('data.0.byline', 'Featuring Tunde Stitch House')
            ->assertJsonPath('data.0.tailor.slug', $this->profile->slug);
    }

    public function test_a_tailor_cannot_edit_or_delete_another_tailors_story(): void
    {
        $id = $this->actingAs($this->tailor)->postJson('/api/v1/me/stories', $this->story())->json('data.id');
        $other = TailorProfile::factory()->create()->user;

        $this->actingAs($other)->postJson("/api/v1/me/stories/{$id}", $this->story())->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/v1/me/stories/{$id}")->assertForbidden();
        $this->assertDatabaseHas('stories', ['id' => $id]);
    }
}
