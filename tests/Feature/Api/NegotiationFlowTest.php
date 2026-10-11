<?php

namespace Tests\Feature\Api;

use App\Models\JobRequest;
use App\Models\Offer;
use App\Models\Order;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NegotiationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailorA;

    private User $tailorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->tailorA = TailorProfile::factory()->create()->user;
        $this->tailorB = TailorProfile::factory()->create()->user;
    }

    private function postRequest(array $overrides = []): string
    {
        $res = $this->actingAs($this->customer)->postJson('/api/v1/job-requests', array_merge([
            'title' => 'Navy senator for a wedding',
            'garment_category' => 'senator',
            'description' => 'Classic senator with subtle gold embroidery on the placket and cuffs.',
            'mode' => 'budget',
            'budget_max_minor' => 3_000_000,
            'fabric_status' => 'own_fabric',
            'city' => 'Abuja',
            'needed_by' => now()->addDays(21)->toDateString(),
            'fitting_required' => true,
        ], $overrides));
        $res->assertCreated();

        return $res->json('data.id');
    }

    public function test_full_budget_journey_from_request_to_order(): void
    {
        $id = $this->postRequest();

        // Tailor A accepts the budget as-is; no explanation required.
        $a = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 3_000_000, 'delivery_days' => 10, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0,
        ])->assertCreated()->assertJsonPath('data.at_customer_budget', true);

        // Tailor B counters higher with an explanation.
        $this->actingAs($this->tailorB)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 3_500_000, 'delivery_days' => 7, 'message' => 'Hand embroidery on cuffs takes two extra days of work.',
        ])->assertCreated();

        // Customer compares: both offers, with breakdowns and rule-based flags.
        $show = $this->actingAs($this->customer)->getJson("/api/v1/job-requests/{$id}")->assertOk();
        $this->assertCount(2, $show->json('data.offers'));
        $this->assertSame('rules', $show->json('data.offers.0.comparison.source'));
        $this->assertNotNull($show->json('data.offers.0.cost_breakdown.total'));

        // Accept A -> order created with locked spec; B becomes not_selected.
        $order = $this->actingAs($this->customer)->postJson('/api/v1/offers/'.$a->json('data.id').'/accept', $this->acceptPayload($a->json('data.id')))
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.spec.terms.amount_minor', 3_000_000)
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertSame('awarded', JobRequest::query()->where('public_id', $id)->value('status'));
        $this->assertSame('not_selected', Offer::query()->where('tailor_id', $this->tailorB->id)->value('status'));

        // Service fee: 2.5% of ₦30,000 = ₦750
        $order->assertJsonPath('data.costs.service_fee.amount_minor', 75_000);
        $order->assertJsonPath('data.costs.total.amount_minor', 3_075_000);
        // Customer never sees the tailor's commission.
        $this->assertNull($order->json('data.tailor_payout'));
    }

    public function test_accept_is_idempotent_and_single_award(): void
    {
        $id = $this->postRequest();
        $offerId = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 3_000_000, 'delivery_days' => 10,
        ])->json('data.id');

        $first = $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))->assertCreated();
        $retry = $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))->assertOk();

        $this->assertSame($first->json('data.reference'), $retry->json('data.reference'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_budget_counter_without_explanation_is_rejected(): void
    {
        $id = $this->postRequest();
        $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 3_500_000, 'delivery_days' => 10, 'message' => 'more',
        ])->assertStatus(422)->assertJsonPath('code', 'explanation_required');
    }

    public function test_counteroffer_rounds_and_tailor_agreement(): void
    {
        $id = $this->postRequest(['mode' => 'open', 'budget_max_minor' => null]);
        $offerId = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 4_000_000, 'delivery_days' => 10,
        ])->json('data.id');

        // Customer counters; customer cannot accept while waiting on tailor.
        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/counter", ['amount_minor' => 3_200_000])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_tailor');
        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        // Tailor agrees to the countered amount (does not consume a round).
        $this->actingAs($this->tailorA)->postJson("/api/v1/offers/{$offerId}/revise", ['amount_minor' => 3_200_000])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_customer')->assertJsonPath('data.rounds.tailor_used', 0);

        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))
            ->assertCreated()->assertJsonPath('data.costs.tailoring.amount_minor', 3_200_000);
    }

    public function test_customer_counter_limit_is_enforced(): void
    {
        config(['marketplace.offers.max_counter_rounds_per_side' => 1]);
        $this->app->forgetInstance(\App\Domain\Negotiation\OfferRules::class);

        $id = $this->postRequest(['mode' => 'open', 'budget_max_minor' => null]);
        $offerId = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", ['amount_minor' => 4_000_000, 'delivery_days' => 5])->json('data.id');

        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/counter", ['amount_minor' => 3_000_000])->assertOk();
        $this->actingAs($this->tailorA)->postJson("/api/v1/offers/{$offerId}/revise", ['amount_minor' => 3_800_000])->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/counter", ['amount_minor' => 3_300_000])
            ->assertStatus(422)->assertJsonPath('code', 'counter_limit');
    }

    public function test_one_offer_per_tailor_per_request(): void
    {
        $id = $this->postRequest();
        $payload = ['amount_minor' => 3_000_000, 'delivery_days' => 10];
        $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", $payload)->assertCreated();
        $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", $payload)
            ->assertStatus(422)->assertJsonPath('code', 'duplicate_offer');
    }

    public function test_expired_offers_cannot_be_accepted_and_are_swept(): void
    {
        $id = $this->postRequest();
        $offerId = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 10])->json('data.id');

        $this->travel(49)->hours();

        $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))
            ->assertStatus(422)->assertJsonPath('code', 'offer_expired');

        Artisan::call('marketplace:expire-stale');
        $this->assertSame('expired', Offer::query()->find($offerId)->status);
    }

    public function test_order_status_follows_server_side_transitions(): void
    {
        config(['marketplace.disputes_enabled' => true]);
        $id = $this->postRequest();
        $offerId = $this->actingAs($this->tailorA)->postJson("/api/v1/job-requests/{$id}/offers", ['amount_minor' => 3_000_000, 'delivery_days' => 10])->json('data.id');
        $ref = $this->actingAs($this->customer)->postJson("/api/v1/offers/{$offerId}/accept", $this->acceptPayload($offerId))->json('data.reference');

        $move = fn (User $u, string $to, ?string $note = null) => $this->actingAs($u)->postJson("/api/v1/orders/{$ref}/transitions", ['status' => $to, 'note' => $note]);

        $move($this->customer, 'in_progress')->assertStatus(409);           // only the tailor starts work
        $move($this->tailorA, 'delivered')->assertStatus(409);              // cannot skip stages
        $move($this->tailorA, 'in_progress')->assertOk();
        $move($this->tailorA, 'ready')->assertOk();
        $move($this->tailorA, 'delivered')->assertOk();
        $move($this->tailorA, 'completed')->assertStatus(409);              // only the customer completes
        $move($this->customer, 'disputed', 'short')->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $done = $move($this->customer, 'completed')->assertOk();

        $this->assertSame(['confirmed', 'in_progress', 'ready', 'delivered', 'completed'], array_column($done->json('data.events'), 'to'));
        $this->assertSame([], $done->json('data.actions'));
    }
}
