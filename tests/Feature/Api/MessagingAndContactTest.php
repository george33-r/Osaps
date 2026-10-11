<?php

namespace Tests\Feature\Api;

use App\Models\Fabric;
use App\Models\FabricVendor;
use App\Models\JobRequest;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingAndContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_two_parties_can_message_about_an_offer(): void
    {
        $customer = User::factory()->create();
        $job = JobRequest::factory()->for($customer, 'customer')->create();
        $tailor = TailorProfile::factory()->create()->user;
        $stranger = User::factory()->create();
        $otherTailor = TailorProfile::factory()->create()->user;

        $offerId = $this->actingAs($tailor)->postJson("/api/v1/job-requests/{$job->public_id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 10])->json('data.id');

        $this->actingAs($customer)->postJson("/api/v1/offers/{$offerId}/messages", ['body' => 'Is lining included?'])->assertCreated()
            ->assertJsonPath('data.author_role', 'customer');
        $this->actingAs($tailor)->postJson("/api/v1/offers/{$offerId}/messages", ['body' => 'Yes, full lining.'])->assertCreated();

        $this->actingAs($stranger)->getJson("/api/v1/offers/{$offerId}/messages")->assertStatus(403);
        $this->actingAs($otherTailor)->postJson("/api/v1/offers/{$offerId}/messages", ['body' => 'Pick me instead'])->assertStatus(403);

        $thread = $this->actingAs($customer)->getJson("/api/v1/offers/{$offerId}/messages")->assertOk();
        $this->assertSame(['Is lining included?', 'Yes, full lining.'], array_column($thread->json('data'), 'body'));
        $this->assertSame([true, false], array_column($thread->json('data'), 'mine'));
    }

    public function test_messages_close_with_the_offer(): void
    {
        $customer = User::factory()->create();
        $job = JobRequest::factory()->for($customer, 'customer')->create();
        $tailor = TailorProfile::factory()->create()->user;
        $offerId = $this->actingAs($tailor)->postJson("/api/v1/job-requests/{$job->public_id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 10])->json('data.id');
        $this->actingAs($customer)->postJson("/api/v1/offers/{$offerId}/decline")->assertOk();

        $this->actingAs($tailor)->postJson("/api/v1/offers/{$offerId}/messages", ['body' => 'Please reconsider'])
            ->assertStatus(422)->assertJsonPath('code', 'offer_closed');
    }

    public function test_vendor_contact_is_for_signed_in_users_only_and_never_in_public_json(): void
    {
        $vendor = FabricVendor::factory()->create(['phone' => '+2348011112222', 'whatsapp' => '+2348011112222']);
        $fabric = Fabric::factory()->for($vendor, 'vendor')->create();

        $public = $this->getJson("/api/v1/fabrics/{$fabric->slug}")->assertOk();
        $this->assertStringNotContainsString('8011112222', $public->getContent());
        $this->getJson("/api/v1/fabrics/{$fabric->slug}/contact")->assertStatus(401);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/fabrics/{$fabric->slug}/contact")->assertOk()
            ->assertJsonPath('data.whatsapp', '+2348011112222')
            ->assertJsonPath('data.protected_by_osaps', false);
    }

    public function test_open_mode_accepts_a_minimum_without_a_maximum(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/job-requests', [
            'title' => 'Lace gown for a party', 'garment_category' => 'gown', 'occasion' => 'party',
            'description' => 'Fitted lace gown with a sheer yoke and scalloped hem.',
            'mode' => 'open', 'budget_min_minor' => 2_000_000, 'fabric_status' => 'own_fabric',
            'city' => 'Abuja', 'needed_by' => now()->addDays(20)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.budget_min.amount_minor', 2_000_000)->assertJsonPath('data.occasion', 'party');
    }

    public function test_disputes_are_refused_while_switched_off_and_hidden_from_actions(): void
    {
        config(['marketplace.disputes_enabled' => false]);
        $customer = User::factory()->create();
        $job = JobRequest::factory()->for($customer, 'customer')->create();
        $tailor = TailorProfile::factory()->create()->user;
        $offerId = $this->actingAs($tailor)->postJson("/api/v1/job-requests/{$job->public_id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 10])->json('data.id');
        $ref = $this->actingAs($customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))->json('data.reference');
        $this->actingAs($tailor)->postJson("/api/v1/orders/{$ref}/transitions", ['status' => 'in_progress'])->assertOk();

        $order = $this->actingAs($customer)->getJson("/api/v1/orders/{$ref}")->assertOk();
        $this->assertNotContains('disputed', array_column($order->json('data.actions'), 'status'));

        $this->actingAs($customer)->postJson("/api/v1/orders/{$ref}/transitions", ['status' => 'disputed', 'note' => 'The sleeves are wrong.'])
            ->assertStatus(422)->assertJsonPath('code', 'disputes_unavailable');
    }

    public function test_meta_reports_ai_off_without_a_key(): void
    {
        $this->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('data.features.ai_assistant', false)
            ->assertJsonPath('data.features.disputes', false);
    }
}
