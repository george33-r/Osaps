<?php

namespace App\Services;

use App\Domain\Shop\FabricOrderStatus;
use App\Models\Fabric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Trending fabrics come only from real sales: paid orders from the last 30 days, not cancelled.
 * A fabric is shown only after it has at least 3 such orders. Nothing is estimated or seeded.
 */
class TrendingFabricService
{
    public const WINDOW_DAYS = 30;

    public const MIN_ORDERS = 3;

    /** @return Collection<int, array{fabric: Fabric, orders: int, buyers: int}> */
    public function top(int $limit = 8): Collection
    {
        $excluded = [FabricOrderStatus::PENDING_PAYMENT, FabricOrderStatus::CANCELLED];
        $rows = DB::table('fabric_order_items as i')
            ->join('fabric_orders as o', 'o.id', '=', 'i.fabric_order_id')
            ->whereNotIn('o.status', $excluded)
            ->where('o.created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('i.fabric_id')
            ->selectRaw('i.fabric_id, COUNT(DISTINCT o.id) as orders, COUNT(DISTINCT o.customer_id) as buyers, SUM(i.quantity) as units')
            ->havingRaw('COUNT(DISTINCT o.id) >= ?', [self::MIN_ORDERS])
            ->orderByDesc('orders')->orderByDesc('units')->limit(24)->get();

        $fabrics = Fabric::query()->listed()->whereIn('id', $rows->pluck('fabric_id'))->with('vendor', 'media')->get()->keyBy('id');

        return $rows->filter(fn ($r) => $fabrics->has($r->fabric_id))->take($limit)
            ->map(fn ($r) => ['fabric' => $fabrics[$r->fabric_id], 'orders' => (int) $r->orders, 'buyers' => (int) $r->buyers])
            ->values();
    }
}
