<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->roleOf($user) !== null;
    }

    /** Who may attempt a transition. Which transitions are legal is decided by OrderStatus. */
    public function transition(User $user, Order $order): bool
    {
        return $order->roleOf($user) !== null;
    }
}
