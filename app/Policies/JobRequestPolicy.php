<?php

namespace App\Policies;

use App\Models\JobRequest;
use App\Models\User;

class JobRequestPolicy
{
    public function create(User $user): bool
    {
        return $user->isCustomer();
    }

    /** Owners and admins always; tailors while it is open or once they have an offer on it. */
    public function view(User $user, JobRequest $request): bool
    {
        if ($user->id === $request->customer_id || $user->isAdmin()) {
            return true;
        }

        return $user->isTailor()
            && ($request->isOpen() || $request->offers()->where('tailor_id', $user->id)->exists());
    }

    public function cancel(User $user, JobRequest $request): bool
    {
        return $user->id === $request->customer_id || $user->isAdmin();
    }

    public function makeOffer(User $user, JobRequest $request): bool
    {
        return $user->isTailor() && $user->id !== $request->customer_id;
    }
}
