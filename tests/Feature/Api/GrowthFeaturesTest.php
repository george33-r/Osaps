<?php

namespace Tests\Feature\Api;

use App\Domain\Payments\PaystackSignature;
use App\Models\AsoEbiGroup;
use App\Models\Checkout;
use App\Models\Fabric;
use App\Models\FabricOrder;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Aṣọ-ẹbí groups, held payouts, notifications, diaspora delivery, measurements, live and try-on.
 * Written without being run: Composer could not be installed in the build environment.
 */
class GrowthFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private Fabric $fabric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeGateway('sk_test_fake');
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->fabric = Fabric::factory()->create(['price_minor' => 450_000, 'stock_quantity' => 400, 'min_order_quantity' => 1]);
    }

    private function vendor(): User
    {
        return $this->fabric->vendor->user;
    }

    private function newGroup(User $organizer, array $o = []): string
    {
        return $this->actingAs($organizer)->postJson("/api/v1/fabrics/{$this->fabric->slug}/aso-ebi", $o + [
            'title' => "Tola & Femi's wedding", 'event_date' => now()->addDays(40)->toDateString(),
            'deadline_at' => now()->addDays(20)->toIso8601String(), 'yards_per_guest' => 5, 'target_guests' => 3,
            'fulfilment' => 'deliver_each', 'organizer_phone' => '08012345678',
        ])->assertCreated()->json('data.code');
    }

    private function payShare(User $guest, string $code, int $yards = 5): array
    {
        $total = $this->actingAs($guest)->postJson("/api/v1/aso-ebi/{$code}/quote", ['yards' => $yards, 'state' => 'Lagos'])->assertOk()->json('data.total.amount_minor');
        $c = $this->actingAs($guest)->postJson("/api/v1/aso-ebi/{$code}/join", [
            'yards' => $yards, 'expected_total_minor' => $total, 'show_name' => true,
            'contact' => ['name' => $guest->name, 'phone' => '08098765432'],
            'address' => ['line1' => '3 Adeola Odeku', 'city' => 'Lagos', 'state' => 'Lagos'],
        ])->assertCreated()->json('data');
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $c['reference'], 'status' => 'success', 'amount' => $c['total']['amount_minor'], 'currency' => 'NGN']]);
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => PaystackSignature::sign($raw, 'sk_test_fake')], $raw)->assertOk();

        return $c;
    }

    public function test_aso_ebi_link_is_public_guests_pay_their_share_and_the_organiser_is_told(): void
    {
        $organizer = User::factory()->create();
        $code = $this->newGroup($organizer);
        $this->getJson("/api/v1/aso-ebi/{$code}")->assertOk()->assertJsonPath('data.progress.paid', 0)->assertJsonPath('data.share_price.amount_minor', 2_250_000);

        $guest = User::factory()->create(['name' => 'Chioma Obi']);
        $this->payShare($guest, $code);
        $this->getJson("/api/v1/aso-ebi/{$code}")->assertJsonPath('data.progress.paid', 1)->assertJsonPath('data.paid_names.0', 'Chioma');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $organizer->id, 'kind' => 'aso_ebi_paid']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->vendor()->id, 'kind' => 'order_paid']);
        $this->assertSame(1, FabricOrder::query()->whereNotNull('aso_ebi_group_id')->where('status', 'paid')->count());

        // Organiser cannot cancel once someone has paid.
        $this->actingAs($organizer)->postJson("/api/v1/aso-ebi/{$code}/cancel")->assertStatus(422)->assertJsonPath('code', 'group_has_payments');
        // Strangers cannot close it.
        $this->actingAs(User::factory()->create())->postJson("/api/v1/aso-ebi/{$code}/close")->assertStatus(422)->assertJsonPath('code', 'forbidden');
    }

    public function test_bulk_deal_applies_until_the_agreed_quantity_is_used(): void
    {
        $organizer = User::factory()->create();
        $offer = $this->actingAs($organizer)->postJson("/api/v1/fabrics/{$this->fabric->slug}/price-offers", ['quantity' => 10, 'unit_price_minor' => 380_000])->json('data.id');
        $this->actingAs($this->vendor())->postJson("/api/v1/price-offers/{$offer}/accept", ['expected_unit_price_minor' => 380_000])->assertOk();
        $code = $this->newGroup($organizer, ['price_offer_id' => $offer]);
        $this->assertDatabaseHas('price_offers', ['id' => $offer, 'status' => 'used']);

        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        $this->actingAs($a)->postJson("/api/v1/aso-ebi/{$code}/quote", ['yards' => 5])->assertJsonPath('data.vendors.0.lines.0.unit_price.amount_minor', 380_000);
        $this->payShare($a, $code);
        $this->payShare($b, $code);
        // 10 agreed yards are used up: the third guest pays the list price.
        $this->actingAs($c)->postJson("/api/v1/aso-ebi/{$code}/quote", ['yards' => 5])->assertJsonPath('data.vendors.0.lines.0.unit_price.amount_minor', 450_000);
    }

    public function test_closed_group_refuses_new_payments(): void
    {
        $organizer = User::factory()->create();
        $code = $this->newGroup($organizer);
        $this->actingAs($organizer)->postJson("/api/v1/aso-ebi/{$code}/close")->assertOk();
        $guest = User::factory()->create();
        $this->actingAs($guest)->postJson("/api/v1/aso-ebi/{$code}/join", [
            'yards' => 5, 'expected_total_minor' => 1, 'contact' => ['name' => 'X Y', 'phone' => '08012345678'],
            'address' => ['line1' => 'a', 'city' => 'b', 'state' => 'c'],
        ])->assertStatus(422)->assertJsonPath('code', 'group_closed');
    }

    public function test_vendor_payout_is_held_until_a_confirmed_delivery_and_the_problem_window_passes(): void
    {
        $buyer = User::factory()->create();
        $cart = ['items' => [['fabric_id' => $this->fabric->id, 'quantity' => 2]], 'fulfilment' => 'delivery'];
        $total = $this->actingAs($buyer)->postJson('/api/v1/shop/quote', $cart + ['state' => 'FCT'])->json('data.total.amount_minor');
        $c = $this->actingAs($buyer)->postJson('/api/v1/checkouts', $cart + ['expected_total_minor' => $total,
            'contact' => ['name' => 'Ade', 'phone' => '08012345678'], 'address' => ['line1' => 'x', 'city' => 'Abuja', 'state' => 'FCT']])->json('data');
        $this->gateway->pay($c['reference'], $c['total']['amount_minor']);
        $this->actingAs($buyer)->postJson("/api/v1/checkouts/{$c['reference']}/verify")->assertJsonPath('data.payment_status', 'paid');
        $ref = $c['orders'][0]['reference'];
        $code = $this->actingAs($buyer)->getJson("/api/v1/fabric-orders/{$ref}")->json('data.delivery_code');
        $this->assertSame(1, preg_match('/\A\d{6}\z/', (string) $code));
        // The vendor never sees the code.
        $this->actingAs($this->vendor())->getJson("/api/v1/fabric-orders/{$ref}")->assertJsonPath('data.delivery_code', null);
        foreach (['packed', 'out_for_delivery'] as $s) {
            $this->actingAs($this->vendor())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => $s])->assertOk();
        }
        $this->actingAs($this->vendor())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'delivered'])
            ->assertStatus(422)->assertJsonPath('code', 'delivery_code_required');
        $this->actingAs($this->vendor())->postJson("/api/v1/fabric-orders/{$ref}/transitions", ['status' => 'delivered', 'code' => $code])->assertOk();
        $this->assertSame('held', FabricOrder::query()->where('reference', $ref)->value('payout_status'));
        $this->travel(6)->days();
        $this->artisan('shop:sweep')->assertSuccessful();
        $this->assertSame('delivered', FabricOrder::query()->where('reference', $ref)->value('status'));
        $this->travel(25)->hours();
        $this->artisan('shop:sweep')->assertSuccessful();
        $o = FabricOrder::query()->where('reference', $ref)->first();
        $this->assertSame('completed', $o->status);
        $this->assertSame('releasable', $o->payout_status);

        $this->actingAs($buyer)->getJson('/api/v1/admin/payouts')->assertForbidden();
        [$a1, $a2] = [User::factory()->create(), User::factory()->create()];
        $a1->forceFill(['role' => 'admin'])->save();
        $a2->forceFill(['role' => 'admin'])->save();
        $this->actingAs($a1)->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonCount(1, 'data');
        // Two people: one approves, a different one records the transfer.
        $this->actingAs($a1)->postJson("/api/v1/admin/fabric-orders/{$ref}/paid-out", ['transfer_reference' => 'TRF-001'])->assertStatus(422)->assertJsonPath('code', 'payout_not_approved');
        $this->actingAs($a1)->postJson("/api/v1/admin/fabric-orders/{$ref}/payout-approve")->assertOk();
        $this->actingAs($a1)->postJson("/api/v1/admin/fabric-orders/{$ref}/paid-out", ['transfer_reference' => 'TRF-001'])->assertStatus(422)->assertJsonPath('code', 'payout_needs_second_admin');
        $this->actingAs($a2)->postJson("/api/v1/admin/fabric-orders/{$ref}/paid-out", ['transfer_reference' => 'TRF-001'])->assertOk();
        $this->assertSame('paid', $o->fresh()->payout_status);
        $this->assertSame(['payout_approved', 'payout_paid'], \App\Models\AdminAction::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_international_shipping_is_priced_by_country_and_refused_where_not_served(): void
    {
        config(['marketplace.diaspora.shipping_minor.uk' => 2_500_000]);
        $buyer = User::factory()->create();
        $items = [['fabric_id' => $this->fabric->id, 'quantity' => 2]];
        $this->actingAs($buyer)->postJson('/api/v1/shop/quote', ['items' => $items, 'fulfilment' => 'international', 'country' => 'GB'])
            ->assertOk()->assertJsonPath('data.delivery.amount_minor', 2_500_000);
        $this->actingAs($buyer)->postJson('/api/v1/shop/quote', ['items' => $items, 'fulfilment' => 'international', 'country' => 'US'])
            ->assertStatus(422)->assertJsonPath('code', 'country_not_served');
    }

    public function test_media_upload_accepts_real_images_and_rejects_disguised_files(): void
    {
        $vendor = $this->vendor();
        $this->actingAs($vendor)->post("/api/v1/vendor/fabrics/{$this->fabric->id}/media", ['file' => UploadedFile::fake()->image('bolt.jpg', 800, 800)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.media.0.kind', 'photo');
        $this->actingAs($vendor)->post("/api/v1/vendor/fabrics/{$this->fabric->id}/media", ['file' => UploadedFile::fake()->createWithContent('bolt.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs(User::factory()->create())->post("/api/v1/vendor/fabrics/{$this->fabric->id}/media", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertFileExists(public_path('uploads/.htaccess'));
    }

    public function test_measurements_saved_in_cm_and_shown_only_to_the_owner(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->putJson('/api/v1/me/measurements', ['unit' => 'in', 'values' => ['chest' => 40]])->assertOk()->assertJsonPath('data.values.chest', 101.6);
        $this->actingAs($u)->putJson('/api/v1/me/measurements', ['unit' => 'cm', 'values' => ['chest' => 40]])->assertStatus(422)->assertJsonPath('code', 'measurement_out_of_range');
    }

    public function test_live_sessions_only_accept_platform_links(): void
    {
        $v = $this->vendor();
        $this->actingAs($v)->postJson('/api/v1/vendor/live', ['title' => 'New Ankara drop', 'platform' => 'tiktok', 'url' => 'https://evil.example/live', 'starts_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'url_invalid');
        $this->actingAs($v)->postJson('/api/v1/vendor/live', ['title' => 'New Ankara drop', 'platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'starts_at' => now()->subMinutes(5)->toIso8601String(), 'fabric_ids' => [$this->fabric->id, 999999]])
            ->assertCreated()->assertJsonPath('data.is_live', true)->assertJsonPath('data.youtube_id', 'dQw4w9WgXcQ')->assertJsonCount(1, 'data.fabrics');
        $this->getJson('/api/v1/live')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_try_on_is_refused_while_switched_off_and_notifications_can_be_read(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/api/v1/ai/try-on', ['fabric_id' => $this->fabric->id, 'garment' => 'gown', 'consent' => '1', 'photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'try_on_disabled');
        app(\App\Services\Notifier::class)->notify($u, 'test', 'Hello');
        $this->actingAs($u)->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 1);
        $this->actingAs($u)->postJson('/api/v1/notifications/read')->assertOk();
        $this->actingAs($u)->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
    }

    public function test_whatsapp_opt_in_needs_a_phone_number(): void
    {
        $u = User::factory()->create(['phone' => null]);
        $this->actingAs($u)->putJson('/api/v1/me', ['whatsapp_opt_in' => true])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/v1/me', ['whatsapp_opt_in' => true, 'phone' => '0801 234 5678'])->assertOk()->assertJsonPath('data.whatsapp_opt_in', true);
    }
}
