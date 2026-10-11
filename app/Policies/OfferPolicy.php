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

    /** Write a message: only the two parties, never an admin posing as one. */
    public function message(User $user, Offer $offer): bool
    {
        return $user->id === $offer->tailor_id || $user->id === $offer->jobRequest->customer_id;
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
