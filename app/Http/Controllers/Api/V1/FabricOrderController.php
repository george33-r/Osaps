<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FabricOrderResource;
use App\Models\FabricOrder;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FabricOrderController extends Controller
{
    private const WITH = ['items.fabric', 'events', 'vendor', 'checkout', 'group', 'refunds'];

    public function __construct(private readonly CheckoutService $checkouts) {}

    public function index(Request $request): JsonResponse
    {
        $this->checkouts->sweep();
        $orders = FabricOrder::query()->where('customer_id', $request->user()->id)->with(self::WITH)->latest('id')->limit(50)->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->present($o, 'customer', $request))->values()]);
    }

    public function vendor(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        if ($vendor === null) {
            return response()->json(['data' => [], 'meta' => ['is_vendor' => false]]);
        }
        $this->checkouts->sweep();
        // Vendors only see orders once they are paid: an unpaid order is not theirs to act on yet.
        $orders = FabricOrder::query()->where('fabric_vendor_id', $vendor->id)
            ->whereNotIn('status', ['pending_payment'])
            ->with(self::WITH)->latest('id')->limit(100)->get();

        return response()->json([
            'data' => $orders->map(fn ($o) => $this->present($o, 'vendor', $request))->values(),
            'meta' => ['is_vendor' => true],
        ]);
    }

    public function show(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        return response()->json(['data' => $this->present($fabricOrder->load(self::WITH), $this->viewer($request, $fabricOrder), $request)]);
    }

    public function transition(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        $viewer = $this->viewer($request, $fabricOrder);
        $data = $request->validate([
            'status' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:12'],
        ]);
        $order = $this->checkouts->transition($request->user(), $fabricOrder, $data['status'], $data['note'] ?? null, $data['code'] ?? null);

        return response()->json(['data' => $this->present($order->load(self::WITH), $viewer, $request)]);
    }

    /** The buyer reports a problem. Pauses completion and the vendor's payout until an admin settles it. */
    public function dispute(Request $request, FabricOrder $fabricOrder, \App\Services\TrustService $trust): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Domain\Shop\DisputeRules::REASONS))],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        abort_unless($fabricOrder->customer_id === $request->user()->id, 403);
        $order = $trust->openDispute($request->user(), $fabricOrder, $data['reason'], $data['details']);

        return response()->json(['data' => $this->present($order->load(self::WITH), 'customer', $request)]);
    }

    private function viewer(Request $request, FabricOrder $order): string
    {
        $user = $request->user();
        if ($order->customer_id === $user->id) {
            return 'customer';
        }
        if ($user->isAdmin()) {
            return 'admin';
        }
        $order->loadMissing('vendor');
        abort_unless($order->vendor?->user_id === $user->id && $order->status !== 'pending_payment', 403);

        return 'vendor';
    }

    private function present(FabricOrder $order, string $viewer, Request $request): array
    {
        return (new FabricOrderResource($order))->withExtra(['viewer' => $viewer])->toArray($request);
    }
}
