<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shop\PayoutRules;
use App\Http\Controllers\Controller;
use App\Models\FabricOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admins pay vendors for completed orders (by bank transfer, outside OSAPS) and record it here. */
class AdminPayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $orders = FabricOrder::query()->with('vendor')->where('payout_status', PayoutRules::RELEASABLE)->orderBy('completed_at')->limit(300)->get();

        return response()->json(['data' => $orders->groupBy('fabric_vendor_id')->map(function ($list) {
            $v = $list->first()->vendor;

            return [
                'vendor' => [
                    'business_name' => $v?->business_name,
                    'bank_name' => $v?->bank_name,
                    'bank_account_name' => $v?->bank_account_name,
                    'bank_account_number' => $v?->bank_account_number, // decrypted for admins only
                ],
                'amount_minor' => $list->sum(fn ($o) => $o->subtotal_minor + $o->delivery_minor - $o->vendor_commission_minor),
                'orders' => $list->map(fn ($o) => ['reference' => $o->reference, 'completed_at' => $o->completed_at?->toIso8601String(),
                    'amount_minor' => $o->subtotal_minor + $o->delivery_minor - $o->vendor_commission_minor])->values(),
            ];
        })->values()]);
    }

    public function markPaid(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        DB::transaction(function () use ($fabricOrder, $request) {
            $o = FabricOrder::query()->lockForUpdate()->findOrFail($fabricOrder->id);
            abort_unless($o->payout_status === PayoutRules::RELEASABLE, 409, 'This order is not waiting for a payout.');
            $o->payout_status = PayoutRules::PAID;
            $o->paid_out_at = now();
            $o->save();
            $o->events()->create(['actor_id' => $request->user()->id, 'actor_role' => 'admin', 'from_status' => $o->status, 'to_status' => $o->status, 'note' => 'Vendor payout sent.']);
        });

        return response()->json(['data' => ['ok' => true]]);
    }
}
