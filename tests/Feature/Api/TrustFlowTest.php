<?php

namespace Tests\Feature\Api;

use App\Domain\Payments\PaystackSignature;
use App\Models\Fabric;
use App\Models\FabricOrder;
use App\Models\FabricVendor;
use App\Models\Refund;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Buyer protection and the back office: delivery codes, problem reports, refunds, vendor checks,
 * two-person payouts, password reset, saved carts and security headers.
 */
class TrustFlowTest extends TestCase
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
        $this->fabric = Fabric::factory()->create(['price_minor' => 450_000, 'stock_quantity' => 50, 'min_order_quantity' => 1]);
    }

    private function vendor(): User
    {
        return $this->fabric->vendor->user;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Buy 2 yards, pay, and return the order reference. */
    private function paidOrder(): string
    {
        $cart = ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 2]], 'fulfilment' => 'delivery'];
        $total = $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $cart + ['state' => 'Lagos'])->assertOk()->json('data.total.amount_minor');
        $c = $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $cart + ['expected_total_minor' => $total,
            'contact' => ['name' => 'Ada', 'phone' => '08012345678'], 'address' => ['line1' => '1 Marina', 'city' => 'Lagos', 'state' => 'Lagos']])
            ->assertCreated()->json('data');
        $this->gateway->pay($c['reference'], $c['total']['amount_minor']);
        $this->actingAs($this->buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertJsonPath('data.payment_status', 'paid');

        return $c['orders'][0]['reference'];
    }

    private function move(User $who, string $ref, string $to, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who)->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => $to] + $extra);
    }

    private function webhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => PaystackSignature::sign($raw, 'sk_test_fake'),
        ], $raw);
    }

    public function test_wrong_delivery_codes_are_counted_and_lock_after_five(): void
    {
        $ref = $this->paidOrder();
        $this->move($this->vendor(), $ref, 'packed')->assertOk();
        $this->move($this->vendor(), $ref, 'out_for_delivery')->assertOk();
        $real = FabricOrder::query()->where('reference', $ref)->value('delivery_code');
        $wrong = $real === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->move($this->vendor(), $ref, 'delivered', ['code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'delivery_code_wrong');
        }
        $this->move($this->vendor(), $ref, 'delivered', ['code' => $real])->assertStatus(422)->assertJsonPath('code', 'delivery_code_locked');
        // The buyer can still confirm themselves.
        $this->move($this->buyer, $ref, 'delivered')->assertOk()->assertJsonPath('data.delivered_by', 'buyer');
    }

    public function test_unverified_shops_are_hidden_until_an_admin_verifies_them(): void
    {
        $vendorUser = User::factory()->fabricVendor()->create();
        $shop = FabricVendor::factory()->unverified()->create(['user_id' => $vendorUser->id, 'phone' => null]);
        $hidden = Fabric::factory()->create(['fabric_vendor_id' => $shop->id]);

        $this->getJson("/api/v1/fabrics/{$hidden->slug}")->assertNotFound();
        $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', ['items' => [['fabric_id' => $hidden->id, 'quantity' => 1]], 'fulfilment' => 'pickup'])
            ->assertOk()->assertJsonPath('data.blocking.0.code', 'fabric_unavailable');
        $this->actingAs($this->buyer)->postJson("/api/v1/fabrics/{$hidden->slug}/price-offers", ['quantity' => 1, 'unit_price_minor' => 1])->assertStatus(422);

        // Saving a complete profile sends it for review.
        $this->actingAs($vendorUser)->putJson('/api/v1/me/vendor-profile', [
            'business_name' => $shop->business_name, 'city' => 'Lagos', 'market' => 'Balogun', 'phone' => '08030000001',
            'bank_name' => 'GTBank', 'bank_account_name' => $shop->business_name, 'bank_account_number' => '0123456780',
        ])->assertOk()->assertJsonPath('data.verification.status', 'pending');

        $admin = $this->admin();
        $this->actingAs($this->buyer)->getJson('/api/v1/admin/vendors')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/admin/vendors')->assertOk()->assertJsonPath('data.0.id', $shop->id);
        $this->actingAs($admin)->postJson("/api/v1/admin/vendors/{$shop->id}/review", ['to' => 'rejected'])->assertStatus(422)->assertJsonPath('code', 'verification_note_required');
        $this->actingAs($admin)->postJson("/api/v1/admin/vendors/{$shop->id}/review", ['to' => 'verified'])->assertOk();
        $this->getJson("/api/v1/fabrics/{$hidden->slug}")->assertOk();

        // New bank details on a verified shop send it back for a check.
        $this->actingAs($vendorUser)->putJson('/api/v1/me/vendor-profile', [
            'business_name' => $shop->business_name, 'city' => 'Lagos', 'market' => 'Balogun', 'phone' => '08030000001',
            'bank_name' => 'GTBank', 'bank_account_name' => 'Someone Else', 'bank_account_number' => '9999999999',
        ])->assertOk()->assertJsonPath('data.verification.status', 'pending');
        $this->getJson("/api/v1/fabrics/{$hidden->slug}")->assertNotFound();
    }

    public function test_problem_report_blocks_completion_and_settles_with_a_paystack_refund(): void
    {
        $ref = $this->paidOrder();
        $this->actingAs($this->buyer)->postJson("/api/v1/fabric-orders/{$ref}/dispute", ['reason' => 'not_received', 'details' => 'Rider never arrived.'])
            ->assertOk()->assertJsonPath('data.dispute.status', 'open');
        $this->actingAs($this->buyer)->postJson("/api/v1/fabric-orders/{$ref}/dispute", ['reason' => 'other', 'details' => 'Again and again'])
            ->assertStatus(422)->assertJsonPath('code', 'dispute_exists');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/fabric-orders/{$ref}/dispute", ['reason' => 'other', 'details' => 'Not my order at all'])->assertForbidden();

        $this->move($this->buyer, $ref, 'delivered')->assertStatus(409); // still paid: not yet dispatched
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/v1/admin/disputes')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->postJson("/api/v1/admin/fabric-orders/{$ref}/resolve", ['resolution' => 'refund', 'note' => 'Vendor confirmed it was never sent.'])->assertOk();

        $o = FabricOrder::query()->where('reference', $ref)->first();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('withheld', $o->payout_status);
        $this->assertSame('refunded', $o->dispute_status);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame($o->total_minor, $refund->amount_minor);
        $this->assertSame('pending', $refund->status);
        $this->assertCount(1, $this->gateway->refunds);

        // No double refund.
        $this->actingAs($admin)->postJson("/api/v1/admin/fabric-orders/{$ref}/refund", ['reason' => 'Trying again'])->assertStatus(422)->assertJsonPath('code', 'refund_done');

        // Paystack confirms.
        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => $refund->provider_refund_id, 'status' => 'processed', 'amount' => $refund->amount_minor, 'transaction_reference' => $o->checkout->reference]])->assertOk();
        $this->assertSame('processed', $refund->fresh()->status);
        $this->actingAs($this->buyer)->getJson("/api/v1/fabric-orders/{$ref}")->assertJsonPath('data.refunds.0.status', 'processed');
    }

    public function test_open_problem_report_stops_automatic_completion(): void
    {
        $ref = $this->paidOrder();
        $this->move($this->vendor(), $ref, 'packed')->assertOk();
        $this->move($this->vendor(), $ref, 'out_for_delivery')->assertOk();
        $this->move($this->buyer, $ref, 'delivered')->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/v1/fabric-orders/{$ref}/dispute", ['reason' => 'damaged', 'details' => 'Holes all along one edge.'])->assertOk();
        $this->move($this->buyer, $ref, 'completed')->assertStatus(422)->assertJsonPath('code', 'dispute_open');
        $this->travel(10)->days();
        $this->artisan('shop:sweep')->assertSuccessful();
        $this->assertSame('delivered', FabricOrder::query()->where('reference', $ref)->value('status'));

        $this->actingAs($this->admin())->postJson("/api/v1/admin/fabric-orders/{$ref}/resolve", ['resolution' => 'release', 'note' => 'Photos show the fabric was fine.'])->assertOk();
        $o = FabricOrder::query()->where('reference', $ref)->first();
        $this->assertSame('completed', $o->status);
        $this->assertSame('releasable', $o->payout_status);
    }

    public function test_refund_with_no_answer_from_paystack_is_never_retried_blindly(): void
    {
        $ref = $this->paidOrder();
        $admin = $this->admin();
        $this->move($admin, $ref, 'cancelled', ['note' => 'Vendor out of stock.'])->assertOk();
        $this->gateway->refundFails = 'unknown';
        $this->actingAs($admin)->postJson("/api/v1/admin/fabric-orders/{$ref}/refund", ['reason' => 'Out of stock'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->actingAs($admin)->postJson("/api/v1/admin/fabric-orders/{$ref}/refund", ['reason' => 'Out of stock'])->assertStatus(422)->assertJsonPath('code', 'refund_done');
        $id = Refund::query()->value('id');
        $this->actingAs($admin)->postJson("/api/v1/admin/refunds/{$id}/mark-failed", ['note' => 'Not in the Paystack dashboard.'])->assertOk();
        $this->gateway->refundFails = null;
        $this->actingAs($admin)->postJson("/api/v1/admin/fabric-orders/{$ref}/refund", ['reason' => 'Out of stock'])->assertCreated();
        $this->assertCount(1, $this->gateway->refunds);
    }

    public function test_password_reset_does_not_reveal_accounts_and_works_once(): void
    {
        Notification::fake();
        $a = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $b = $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->buyer->email])->assertOk()->json('message');
        $this->assertSame($a, $b);
        $token = null;
        Notification::assertSentTo($this->buyer, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $body = ['token' => $token, 'email' => $this->buyer->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'];
        $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => $this->buyer->email, 'password' => 'newpass123'])->assertOk();
    }

    public function test_cart_is_saved_to_the_account_and_ignores_other_peoples_offers(): void
    {
        $other = User::factory()->create();
        $theirOffer = $this->actingAs($other)->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", ['quantity' => 2, 'unit_price_minor' => 400_000])->assertCreated()->json('data.id');
        $this->actingAs($this->buyer)->putJson('/api/v1/cart', ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 3, 'price_offer_id' => $theirOffer], ['fabric_id' => 999999, 'quantity' => 1]]])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price_offer_id', null);
        $this->actingAs($this->buyer)->getJson('/api/v1/cart')->assertJsonPath('data.0.quantity', 3);
        $this->actingAs($other)->getJson('/api/v1/cart')->assertJsonCount(0, 'data');
    }

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/api/v1/meta')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_a_guest_sees_prices_then_pays_without_a_sign_up_form(): void
    {
        $cart = ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 2]], 'fulfilment' => 'delivery'];
        $lagos = $this->postJson('/api/v1/shop/quote', $cart + ['state' => 'Lagos'])->assertOk()->json('data.delivery.amount_minor');
        $kano = $this->postJson('/api/v1/shop/quote', $cart + ['state' => 'Kano'])->assertOk()->json('data.delivery.amount_minor');
        $this->assertSame(300_000, $lagos);
        $this->assertSame(600_000, $kano);

        Notification::fake();
        $this->postJson('/api/v1/auth/quick', ['name' => 'Ngozi Eze', 'email' => 'ngozi@example.com', 'phone' => '0803 111 2222'])
            ->assertCreated()->assertJsonPath('data.role', 'customer');
        $this->postJson('/api/v1/auth/quick', ['name' => 'Someone', 'email' => $this->buyer->email, 'phone' => '0803 111 2222'])
            ->assertStatus(409)->assertJsonPath('code', 'account_exists');
        $this->assertTrue(User::query()->where('email', 'ngozi@example.com')->exists());
    }

    public function test_checkout_charges_delivery_for_the_state_and_refuses_unknown_states(): void
    {
        $cart = ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 2]], 'fulfilment' => 'delivery'];
        $total = $this->actingAs($this->buyer)->postJson('/api/v1/shop/quote', $cart + ['state' => 'Kano'])->json('data.total.amount_minor');
        $body = $cart + ['expected_total_minor' => $total, 'contact' => ['name' => 'Ada', 'phone' => '08012345678'],
            'address' => ['line1' => '1 Zoo Road', 'city' => 'Kano', 'state' => 'Kano']];
        $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $body)->assertCreated()->assertJsonPath('data.delivery.amount_minor', 600_000);
        $body['address']['state'] = 'Atlantis';
        $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $body)->assertStatus(422);
        $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', ['fulfilment' => 'pickup'] + $body)->assertStatus(422);
    }

    public function test_express_delivery_costs_more_flags_the_order_and_is_delivery_only(): void
    {
        $cart = ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 2]], 'fulfilment' => 'delivery', 'express' => true];
        $q = $this->postJson('/api/v1/shop/quote', $cart + ['state' => 'Lagos'])->assertOk();
        $this->assertSame(600_000, $q->json('data.delivery.amount_minor'));
        $body = $cart + ['expected_total_minor' => $q->json('data.total.amount_minor'), 'contact' => ['name' => 'Ada', 'phone' => '08012345678'],
            'address' => ['line1' => '1 Allen Avenue', 'city' => 'Ikeja', 'state' => 'Lagos']];
        $this->actingAs($this->buyer)->postJson('/api/v1/checkouts', $body)->assertCreated()
            ->assertJsonPath('data.express', true)->assertJsonPath('data.delivery.amount_minor', 600_000);
        $this->assertTrue((bool) \App\Models\FabricOrder::query()->latest('id')->value('express'));
        $this->postJson('/api/v1/shop/quote', ['fulfilment' => 'arrival'] + $cart + ['state' => 'Lagos'])->assertStatus(422)->assertJsonPath('code', 'express_unavailable');
    }
}
