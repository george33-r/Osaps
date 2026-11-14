<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $column = $user->isTailor() ? 'tailor_id' : 'customer_id';

        return OrderResource::collection(
            Order::query()->where($column, $user->id)
                ->with(['tailor.tailorProfile', 'customer'])
                ->latest('id')->paginate(20)
        );
    }

    public function show(Request $request, Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(['tailor.tailorProfile', 'customer.measurements', 'events']));
    }

    public function transition(TransitionOrderRequest $request, Order $order): OrderResource
    {
        Gate::authorize('transition', $order);
        $order = $this->orders->transition($request->user(), $order, $request->input('status'), $request->input('note'));

        return new OrderResource($order->load(['tailor.tailorProfile', 'customer.measurements', 'events']));
    }

    /** The customer pays for a confirmed tailor order. Returns the Paystack page to open. */
    public function pay(Request $request, Order $order, \App\Services\OrderPaymentService $payments): JsonResponse
    {
        $payment = $payments->start($request->user(), $order, url('/orders/'.$order->reference));

        return response()->json(['data' => ['reference' => $payment->reference, 'amount_minor' => $payment->amount_minor, 'authorization_url' => $payment->authorization_url]]);
    }
}
