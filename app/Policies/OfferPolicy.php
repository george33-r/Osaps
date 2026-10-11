<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;

class OfferPolicy
{
    public function view(User $user, Offer $offer): bool
    {
        return $user->id === $offer->tailor_id
            || $user->id === $offer->jobRequest->customer_id
            || $user->isAdmin();
    }

    /** Accept, decline, counter. */
    public function respondAsCustomer(User $user, Offer $offer): bool
    {
        return $user->id === $offer->jobRequest->customer_id;
    }

    /** Revise, agree, withdraw. */
    public function respondAsTailor(User $user, Offer $offer): bool
    {
        return $user->id === $offer->tailor_id;
    }
}
