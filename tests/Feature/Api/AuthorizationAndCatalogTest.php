<?php

namespace Tests\Feature\Api;

use App\Models\Fabric;
use App\Models\JobRequest;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_customers_cannot_see_or_act_on_a_request(): void
    {
        $job = JobRequest::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/v1/job-requests/{$job->public_id}")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/job-requests/{$job->public_id}/cancel")->assertStatus(403);
    }

    public function test_tailors_see_first_name_only_and_no_other_offers(): void
    {
        $customer = User::factory()->create(['name' => 'Chiamaka Okafor', 'phone' => '+2348000000000']);
        $job = JobRequest::factory()->for($customer, 'customer')->create();
        $tailor = TailorProfile::factory()->create()->user;

        $res = $this->actingAs($tailor)->getJson("/api/v1/job-requests/{$job->public_id}")->assertOk();
        $res->assertJsonPath('data.customer', ['first_name' => 'Chiamaka']);
        $this->assertStringNotContainsString('+2348000000000', $res->getContent());
        $this->assertNull($res->json('data.offers'));
        $this->assertNotNull($res->json('data.match.score'));
    }

    public function test_tailor_cannot_accept_their_own_offer(): void
    {
        $job = JobRequest::factory()->create();
        $tailor = TailorProfile::factory()->create()->user;
        $offerId = $this->actingAs($tailor)->postJson("/api/v1/job-requests/{$job->public_id}/offers", [
            'amount_minor' => 3_000_000, 'delivery_days' => 10,
        ])->json('data.id');

        $this->actingAs($tailor)->postJson("/api/v1/offers/{$offerId}/accept")->assertStatus(403);
    }

    public function test_customers_cannot_post_offers_and_tailors_cannot_post_requests(): void
    {
        $job = JobRequest::factory()->create();
        $customer = User::factory()->create();
        $tailor = TailorProfile::factory()->create()->user;

        $this->actingAs($customer)->postJson("/api/v1/job-requests/{$job->public_id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 5])->assertStatus(403);
        $this->actingAs($tailor)->postJson('/api/v1/job-requests', [
            'title' => 'Something', 'garment_category' => 'senator', 'description' => str_repeat('a', 30),
            'mode' => 'open', 'fabric_status' => 'own_fabric', 'city' => 'Abuja', 'needed_by' => now()->addWeek()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_catalogue_filters_change_results(): void
    {
        Fabric::factory()->create(['category' => 'lace', 'primary_color' => 'cream', 'price_minor' => 1_800_000]);
        Fabric::factory()->create(['category' => 'ankara', 'primary_color' => 'indigo', 'price_minor' => 450_000]);
        Fabric::factory()->create(['category' => 'ankara', 'primary_color' => 'emerald', 'price_minor' => 420_000, 'stock_quantity' => 0]);
        Fabric::factory()->create(['category' => 'ankara', 'status' => 'draft']);

        $this->getJson('/api/v1/fabrics')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/fabrics?category[]=lace')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fabrics?color[]=indigo&color[]=emerald')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/fabrics?max_price=500000&in_stock=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fabrics?sort=price_asc')->assertJsonPath('data.0.price.amount_minor', 420_000);
        $this->getJson('/api/v1/fabrics?category[]=velvet')->assertStatus(422);
    }

    public function test_new_tailors_are_labelled_new_not_given_fake_history(): void
    {
        $profile = TailorProfile::factory()->create();
        $this->getJson("/api/v1/tailors/{$profile->slug}")
            ->assertOk()
            ->assertJsonPath('data.track_record.completed_orders', 0)
            ->assertJsonPath('data.track_record.on_time_rate', null)
            ->assertJsonPath('data.track_record.is_new', true)
            ->assertJsonPath('data.verified', false);
    }
}
