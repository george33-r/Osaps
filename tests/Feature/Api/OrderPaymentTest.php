<?php

namespace Tests\Feature\Api;

use App\Domain\Support\DomainRuleViolation;
use App\Models\JobRequest;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A tailor order is paid through Paystack before the tailor can start work. */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    private string $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->tailor = TailorProfile::factory()->create()->user;

        $job = $this->actingAs($this->customer)->postJson('/api/v1/job-requests', [
            'title' => 'Navy senator', 'garment_category' => 'senator',
            'description' => 'Classic senator with subtle gold embroidery on the placket and cuffs.',
            'mode' => 'budget', 'budget_max_minor' => 3_000_000, 'fabric_status' => 'own_fabric',
            'city' => 'Abuja', 'needed_by' => now()->addDays(21)->toDateString(), 'fitting_required' => true,
        ])->assertCreated();
        $offer = $this->actingAs($this->tailor)->postJson('/api/v1/job-requests/'.$job->json('data.id').'/offers', [
            'amount_minor' => 3_000_000, 'delivery_days' => 10, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0,
        ])->assertCreated();
        $this->ref = $this->actingAs($this->customer)->postJson('/api/v1/offers/'.$offer->json('data.id').'/accept', $this->acceptPayload($offer->json('data.id')))
            ->assertCreated()->json('data.reference');
    }

    private function order(): Order
    {
        return Order::query()->where('reference', $this->ref)->firstOrFail();
    }

    private function charge(int $amount, string $reference, string $currency = 'NGN'): array
    {
        return ['reference' => $reference, 'status' => 'success', 'currency' => $currency, 'amount' => $amount];
    }

    public function test_tailor_cannot_start_work_until_the_customer_has_paid(): void
    {
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'in_progress'])
            ->assertStatus(422)->assertJsonPath('code', 'payment_required');
    }

    public function test_only_the_customer_can_start_a_payment(): void
    {
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/pay")->assertForbidden();
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_customer_gets_a_paystack_page_and_a_pending_payment(): void
    {
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")
            ->assertOk()->assertJsonPath('data.amount_minor', $this->order()->total_minor);

        $this->assertDatabaseHas('order_payments', ['order_id' => $this->order()->id, 'status' => 'pending']);
        // Asking again reuses the same pending attempt.
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")->assertOk();
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_a_short_payment_does_not_mark_the_order_paid(): void
    {
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")->assertOk();
        $payment = OrderPayment::query()->firstOrFail();

        $this->expectException(DomainRuleViolation::class);
        try {
            app(OrderPaymentService::class)->confirmPaid($payment->reference, $this->charge($payment->amount_minor - 1, $payment->reference));
        } finally {
            $this->assertSame('unpaid', $this->order()->payment_status);
        }
    }

    public function test_a_confirmed_payment_lets_the_tailor_start_and_is_recorded(): void
    {
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")->assertOk();
        $payment = OrderPayment::query()->firstOrFail();

        app(OrderPaymentService::class)->confirmPaid($payment->reference, $this->charge($payment->amount_minor, $payment->reference));

        $this->assertSame('paid', $this->order()->payment_status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'in_progress'])->assertOk();
    }

    public function test_a_payment_in_another_currency_is_refused(): void
    {
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")->assertOk();
        $payment = OrderPayment::query()->firstOrFail();

        $this->expectException(DomainRuleViolation::class);
        app(OrderPaymentService::class)->confirmPaid($payment->reference, $this->charge($payment->amount_minor, $payment->reference, 'USD'));
    }

    public function test_completing_an_order_records_when_it_was_completed(): void
    {
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/pay")->assertOk();
        $payment = OrderPayment::query()->firstOrFail();
        app(OrderPaymentService::class)->confirmPaid($payment->reference, $this->charge($payment->amount_minor, $payment->reference));
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'in_progress'])->assertOk();
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'ready'])->assertOk();
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'delivered'])->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/transitions", ['status' => 'completed'])->assertOk();

        $this->assertNotNull($this->order()->completed_at);
    }
}
