<?php

namespace Tests\Feature\Api;

use App\Domain\Shop\FabricOrderStatus;
use App\Models\Fabric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Trending fabrics come only from real paid orders in the last 30 days, and need at least 3 of them. */
class TrendingFabricTest extends TestCase
{
    use RefreshDatabase;

    /** Records one order for a fabric directly, the way a completed checkout leaves it. */
    private function sale(Fabric $fabric, string $status, ?Carbon $at = null): void
    {
        $at ??= now();
        $buyer = User::factory()->create();

        $checkoutId = DB::table('checkouts')->insertGetId([
            'reference' => 'CHK'.Str::upper(Str::random(12)), 'customer_id' => $buyer->id, 'fulfilment' => 'pickup',
            'contact' => json_encode([]), 'address' => null, 'subtotal_minor' => 0, 'delivery_minor' => 0,
            'service_fee_minor' => 0, 'total_minor' => 0, 'currency' => 'NGN', 'payment_status' => 'paid',
            'provider' => 'paystack', 'expires_at' => now()->addHour(), 'created_at' => $at, 'updated_at' => $at,
        ]);
        $orderId = DB::table('fabric_orders')->insertGetId([
            'reference' => 'ORD'.Str::upper(Str::random(12)), 'checkout_id' => $checkoutId, 'customer_id' => $buyer->id,
            'fabric_vendor_id' => $fabric->fabric_vendor_id, 'status' => $status, 'fulfilment' => 'pickup',
            'subtotal_minor' => 0, 'delivery_minor' => 0, 'service_fee_minor' => 0, 'total_minor' => 0,
            'vendor_commission_minor' => 0, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('fabric_order_items')->insert([
            'fabric_order_id' => $orderId, 'fabric_id' => $fabric->id, 'name' => $fabric->name, 'unit' => 'yard',
            'quantity' => 2, 'list_unit_minor' => 1, 'unit_minor' => 1, 'line_total_minor' => 2,
        ]);
    }

    public function test_a_fabric_needs_three_paid_orders_before_it_trends(): void
    {
        $fabric = Fabric::factory()->create();
        $this->sale($fabric, FabricOrderStatus::PAID);
        $this->sale($fabric, FabricOrderStatus::PAID);

        $this->getJson('/api/v1/fabrics/trending')->assertOk()->assertJsonCount(0, 'data');

        $this->sale($fabric, FabricOrderStatus::PACKED);

        $this->getJson('/api/v1/fabrics/trending')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fabric.id', $fabric->id)
            ->assertJsonPath('data.0.trend.orders', 3)
            ->assertJsonPath('data.0.trend.buyers', 3)
            ->assertJsonPath('data.0.trend.window_days', 30);
    }

    public function test_unpaid_and_cancelled_orders_do_not_count(): void
    {
        $fabric = Fabric::factory()->create();
        $this->sale($fabric, FabricOrderStatus::PAID);
        $this->sale($fabric, FabricOrderStatus::PAID);
        $this->sale($fabric, FabricOrderStatus::CANCELLED);
        $this->sale($fabric, FabricOrderStatus::PENDING_PAYMENT);

        $this->getJson('/api/v1/fabrics/trending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_orders_older_than_thirty_days_do_not_count(): void
    {
        $fabric = Fabric::factory()->create();
        $this->sale($fabric, FabricOrderStatus::PAID);
        $this->sale($fabric, FabricOrderStatus::PAID);
        $this->sale($fabric, FabricOrderStatus::PAID, now()->subDays(40));

        $this->getJson('/api/v1/fabrics/trending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_listed_fabrics_are_shown(): void
    {
        $fabric = Fabric::factory()->create(['status' => 'paused']);
        foreach (range(1, 3) as $_) {
            $this->sale($fabric, FabricOrderStatus::PAID);
        }

        $this->getJson('/api/v1/fabrics/trending')->assertOk()->assertJsonCount(0, 'data');
    }
}
