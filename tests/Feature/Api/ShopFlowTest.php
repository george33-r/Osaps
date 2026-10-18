<?php

namespace Tests\Feature\Api;

use App\Domain\Payments\PaystackSignature;
use App\Models\Checkout;
use App\Models\Fabric;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fabric shop: inDrive-style offers, checkout, Paystack confirmation, stock.
 * Written without being run: Composer could not be installed in the build environment.
 */
class ShopFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private User $buyer;

    private Fabric $fabric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeGateway('sk_test_fake');
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->buyer = User::factory()->create();
        $this->fabric = Fabric::factory()->create(['price_minor' => 450_000, 'stock_quantity' => 20]); // ₦4,500
    }

    private function vendorUser(): User
    {
        return $this->fabric->vendor->user;
    }

    private function openOffer(int $unit = 380_000, int $qty = 6): int
    {
        return $this->actingAs($this->buyer)->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", [
            'quantity' => $qty, 'unit_price_minor' => $unit,
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_vendor')->json('data.id');
    }

    private function cart(?int $offerId = null, int $qty = 6, string $fulfilment = 'delivery'): array
    {
        return ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => $qty, 'price_offer_id' => $offerId]], 'fulfilment' => $fulfilment];
    }

    private function checkout(array $cart): array
    {
        $total = $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $cart + ['state' => 'FCT'])->assertOk()->json('data.total.amount_minor');

        return $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $cart + [
            'expected_total_minor' => $total,
            'contact' => ['name' => 'Amaka Obi', 'phone' => '+234 801 234 5678'],
            'address' => ['line1' => '12 Aminu Kano Crescent', 'city' => 'Abuja', 'state' => 'FCT'],
        ])->assertCreated()->json('data');
    }

    private function webhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => PaystackSignature::sign($raw, 'sk_test_fake'),
        ], $raw);
    }

    public function test_full_indrive_negotiation_then_paid_checkout_uses_agreed_price(): void
    {
        $id = $this->openOffer(380_000);

        // Vendor counters at ₦4,200; buyer raises to ₦4,000; vendor accepts.
        $this->actingAs($this->vendorUser())->postJson("/api/v1/price-offers/{$id}/counter", ['unit_price_minor' => 420_000])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_buyer');
        $this->actingAs($this->buyer)->postJson("/api/v1/price-offers/{$id}/counter", ['unit_price_minor' => 400_000])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_vendor');
        $this->actingAs($this->vendorUser())->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 400_000])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $quote = $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $this->cart($id))->assertOk();
        $quote->assertJsonPath('data.vendors.0.lines.0.unit_price.amount_minor', 400_000);
        $quote->assertJsonPath('data.vendors.0.lines.0.saving.amount_minor', 300_000);

        $c = $this->checkout($this->cart($id));
        $this->assertStringStartsWith('https://checkout.paystack.com/', $c['authorization_url']);
        $this->assertSame(6, $this->fabric->fresh()->reserved_quantity);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $c['reference'], 'status' => 'success', 'amount' => $c['total']['amount_minor'], 'currency' => 'NGN']])->assertOk();

        $f = $this->fabric->fresh();
        $this->assertSame(14, $f->stock_quantity);
        $this->assertSame(0, $f->reserved_quantity);
        $this->assertSame('paid', Checkout::query()->where('reference', $c['reference'])->value('payment_status'));
        $this->assertDatabaseHas('price_offers', ['id' => $id, 'status' => 'used']);
    }

    public function test_only_the_side_whose_turn_it_is_can_act_and_strangers_cannot_see(): void
    {
        $id = $this->openOffer();
        $this->actingAs($this->buyer)->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 380_000])
            ->assertStatus(409);
        $this->actingAs(User::factory()->create())->getJson("/api/v1/price-offers/{$id}")->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 380_000])->assertForbidden();
    }

    public function test_offer_floor_and_list_price_are_enforced(): void
    {
        $this->actingAs($this->buyer)->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", ['quantity' => 2, 'unit_price_minor' => 300_000])
            ->assertStatus(422)->assertJsonPath('code', 'below_floor');
        $this->actingAs($this->buyer)->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", ['quantity' => 2, 'unit_price_minor' => 450_000])
            ->assertStatus(422)->assertJsonPath('code', 'at_or_above_list');
        $this->actingAs($this->vendorUser())->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", ['quantity' => 2, 'unit_price_minor' => 400_000])
            ->assertStatus(422)->assertJsonPath('code', 'own_listing');
    }

    public function test_accepting_a_price_that_just_changed_is_refused(): void
    {
        $id = $this->openOffer(380_000);
        $this->actingAs($this->vendorUser())->postJson("/api/v1/price-offers/{$id}/counter", ['unit_price_minor' => 440_000])->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 420_000])
            ->assertStatus(422)->assertJsonPath('code', 'offer_changed');
    }

    public function test_another_buyer_cannot_use_my_agreed_price(): void
    {
        $id = $this->openOffer(380_000);
        $this->actingAs($this->vendorUser())->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 380_000])->assertOk();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/shop/quote', $this->cart($id))
            ->assertOk()->assertJsonPath('data.vendors.0.lines.0.unit_price.amount_minor', 450_000)
            ->assertJsonPath('data.notices.0.code', 'offer_not_usable');
    }

    public function test_checkout_refuses_a_total_the_buyer_did_not_see(): void
    {
        $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $this->cart() + [
            'expected_total_minor' => 1, 'contact' => ['name' => 'Amaka', 'phone' => '08012345678'],
            'address' => ['line1' => 'x street', 'city' => 'Abuja', 'state' => 'FCT'],
        ])->assertStatus(422)->assertJsonPath('code', 'cart_changed');
        $this->assertSame(0, $this->fabric->fresh()->reserved_quantity);
    }

    public function test_cannot_buy_more_than_is_in_stock(): void
    {
        $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $this->cart(null, 25))
            ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.blocking.0.code', 'insufficient_stock');
    }

    public function test_webhook_needs_a_valid_signature_and_replays_are_harmless(): void
    {
        $c = $this->checkout($this->cart());
        $payload = ['event' => 'charge.success', 'data' => ['reference' => $c['reference'], 'status' => 'success', 'amount' => $c['total']['amount_minor'], 'currency' => 'NGN']];

        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], json_encode($payload))
            ->assertStatus(401);
        $this->assertSame('pending', Checkout::query()->where('reference', $c['reference'])->value('payment_status'));

        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk(); // replay
        $this->assertSame(14, $this->fabric->fresh()->stock_quantity); // stock taken once
    }

    public function test_backing_out_of_paystack_and_paying_again_keeps_the_agreed_price_and_stock_straight(): void
    {
        $id = $this->openOffer(380_000);
        $this->actingAs($this->vendorUser())->postJson("/api/v1/price-offers/{$id}/accept", ['expected_unit_price_minor' => 380_000])->assertOk();
        $first = $this->checkout($this->cart($id));
        $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $this->cart($id))
            ->assertJsonPath('data.vendors.0.lines.0.unit_price.amount_minor', 380_000);
        $second = $this->checkout($this->cart($id));
        $this->assertSame('cancelled', Checkout::query()->where('reference', $first['reference'])->value('payment_status'));
        $this->assertSame(6, $this->fabric->fresh()->reserved_quantity);
        $this->assertSame($first['total']['amount_minor'], $second['total']['amount_minor']);
    }

    public function test_underpayment_does_not_mark_paid(): void
    {
        $c = $this->checkout($this->cart());
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $c['reference'], 'status' => 'success', 'amount' => 100, 'currency' => 'NGN']])->assertOk();
        $this->assertSame('pending', Checkout::query()->where('reference', $c['reference'])->value('payment_status'));
    }

    public function test_return_from_paystack_verifies_with_paystack_not_the_browser(): void
    {
        $c = $this->checkout($this->cart());
        $this->actingAs($this->buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertOk()->assertJsonPath('data.payment_status', 'pending');

        $this->gateway->pay($c['reference'], $c['total']['amount_minor']);
        $this->actingAs($this->buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->actingAs($this->buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(14, $this->fabric->fresh()->stock_quantity);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/checkouts/{$c['reference']}")->assertForbidden();
    }

    public function test_unpaid_checkout_releases_stock_when_it_expires(): void
    {
        $c = $this->checkout($this->cart());
        $this->assertSame(6, $this->fabric->fresh()->reserved_quantity);
        $this->travel(61)->minutes();
        $this->artisan('shop:sweep')->assertSuccessful();
        $this->assertSame(0, $this->fabric->fresh()->reserved_quantity);
        $this->assertSame('expired', Checkout::query()->where('reference', $c['reference'])->value('payment_status'));
    }

    public function test_vendor_sees_order_only_after_payment_and_must_use_the_right_dispatch_step(): void
    {
        $c = $this->checkout($this->cart());
        $ref = $c['orders'][0]['reference'];
        $this->actingAs($this->vendorUser())->getJson('/api/v1/vendor/fabric-orders')->assertOk()->assertJsonCount(0, 'data');

        $this->gateway->pay($c['reference'], $c['total']['amount_minor']);
        $this->actingAs($this->buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertOk();

        $this->actingAs($this->vendorUser())->getJson('/api/v1/vendor/fabric-orders')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deliver_to.phone', '+234 801 234 5678');
        $this->actingAs($this->vendorUser())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'packed'])->assertOk();
        $this->actingAs($this->vendorUser())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'ready_for_pickup'])
            ->assertStatus(422)->assertJsonPath('code', 'wrong_dispatch_step');
        $this->actingAs($this->vendorUser())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'out_for_delivery'])->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'delivered'])->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        // The vendor cannot complete an order for the buyer.
        $this->assertFalse(\App\Domain\Shop\FabricOrderStatus::can('delivered', 'completed', 'vendor'));
    }

    public function test_vendor_cannot_set_stock_below_what_buyers_are_paying_for(): void
    {
        $this->checkout($this->cart());
        $this->actingAs($this->vendorUser())->patchJson("/api/v1/vendor/fabrics/{$this->fabric->id}", ['stock_quantity' => 3])->assertStatus(422);
        $this->actingAs(User::factory()->create())->patchJson("/api/v1/vendor/fabrics/{$this->fabric->id}", ['price_minor' => 100])->assertForbidden();
    }
}
