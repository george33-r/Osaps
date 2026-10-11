<?php

namespace App\Services;

use App\Domain\Orders\OrderStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /** Move an order to a new status, enforcing who may do it and recording an audit event. */
    public function transition(User $actor, Order $order, string $to, ?string $note = null): Order
    {
        return DB::transaction(function () use ($actor, $order, $to, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $role = $order->roleOf($actor);
            if ($role === null) {
                throw new AuthorizationException;
            }

            OrderStatus::assert($order->status, $to, $role);

            if ($to === OrderStatus::DISPUTED && ! config('marketplace.disputes_enabled')) {
                throw new DomainRuleViolation('Problem reports go to OSAPS support for now. Contact support and quote your order reference.', 'disputes_unavailable');
            }

            if (OrderStatus::requiresReason($to) && mb_strlen(trim((string) $note)) < 10) {
                throw new DomainRuleViolation('Please give a reason (at least 10 characters).', 'reason_required');
            }

            $from = $order->status;
            $order->status = $to;
            $order->save();

            $order->events()->create([
                'actor_id' => $actor->id,
                'actor_role' => $role,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            return $order;
        });
    }
}
