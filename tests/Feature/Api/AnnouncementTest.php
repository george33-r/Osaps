<?php

namespace Tests\Feature\Api;

use App\Mail\AdminBroadcast;
use App\Models\Announcement;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Announcements reach only people who opted in, carry a signed unsubscribe link, and cannot be sent in quick succession. */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $optedInTailor;

    private User $optedInCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->optedInTailor = TailorProfile::factory()->create()->user;
        $this->optedInTailor->forceFill(['email_updates_opt_in' => true])->save();
        $this->optedInCustomer = User::factory()->create(['email_updates_opt_in' => true]);
        User::factory()->create(); // a customer who never opted in
    }

    private function send(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/admin/announcements', array_merge([
            'audience' => 'everyone',
            'subject' => 'New fabrics this week',
            'body' => 'Fresh ankara and lace are now listed on OSAPS.',
        ], $overrides));
    }

    public function test_audience_counts_only_people_who_opted_in(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/announcements/audience?audience=everyone')
            ->assertOk()->assertJsonPath('data.recipients', 2);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/announcements/audience?audience=tailors')
            ->assertOk()->assertJsonPath('data.recipients', 1);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/announcements/audience?audience=customers')
            ->assertOk()->assertJsonPath('data.recipients', 1);
    }

    public function test_send_queues_one_message_per_opted_in_person_with_an_unsubscribe_link(): void
    {
        Mail::fake();

        $this->send()->assertStatus(202)->assertJsonPath('data.recipients', 2);

        $links = [];
        Mail::assertQueued(AdminBroadcast::class, function (AdminBroadcast $mail) use (&$links) {
            $links[] = $mail->unsubscribeUrl;

            return true;
        });
        $this->assertCount(2, $links);
        foreach ($links as $link) {
            $this->assertStringContainsString('signature=', $link);
        }
        $this->assertSame(1, Announcement::query()->count());
    }

    public function test_signed_unsubscribe_turns_email_updates_off(): void
    {
        Mail::fake();
        $this->send()->assertStatus(202);

        // The link addressed to the customer: the user id sits in the path, before the signature query.
        $marker = '/unsubscribe/'.$this->optedInCustomer->id.'?';
        $link = null;
        Mail::assertQueued(AdminBroadcast::class, function (AdminBroadcast $mail) use (&$link, $marker) {
            if (str_contains((string) $mail->unsubscribeUrl, $marker)) {
                $link = $mail->unsubscribeUrl;
            }

            return true;
        });
        $this->assertNotNull($link);

        $this->get($link)->assertOk();
        $this->assertFalse((bool) $this->optedInCustomer->fresh()->email_updates_opt_in);
        $this->assertTrue((bool) $this->optedInTailor->fresh()->email_updates_opt_in);
    }

    public function test_unsigned_unsubscribe_link_is_refused(): void
    {
        $this->get('/api/v1/email-updates/unsubscribe/'.$this->optedInCustomer->id)->assertForbidden();
        $this->assertTrue((bool) $this->optedInCustomer->fresh()->email_updates_opt_in);
    }

    public function test_second_announcement_within_ten_minutes_is_refused(): void
    {
        Mail::fake();
        $this->send()->assertStatus(202);

        $this->send()->assertStatus(422)->assertJsonPath('code', 'too_soon');
        $this->assertSame(1, Announcement::query()->count());
    }

    public function test_unknown_audience_is_refused(): void
    {
        $this->send(['audience' => 'strangers'])->assertUnprocessable();
    }

    public function test_only_admins_can_send_or_see_the_audience(): void
    {
        $this->actingAs($this->optedInCustomer)->postJson('/api/v1/admin/announcements', [
            'audience' => 'everyone', 'subject' => 'Hello there', 'body' => 'This should never go out.',
        ])->assertForbidden();
        $this->actingAs($this->optedInCustomer)->getJson('/api/v1/admin/announcements/audience?audience=everyone')
            ->assertForbidden();
    }
}
