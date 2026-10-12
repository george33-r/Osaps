<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** The signed-in user's own account. Never used to show other people. */
class UserResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'city' => $this->city,
            'whatsapp_opt_in' => (bool) $this->whatsapp_opt_in,
            'currency' => $this->currency,
            'has_tailor_profile' => $this->when($this->isTailor(), fn () => $this->tailorProfile()->exists()),
            'has_vendor_profile' => $this->when($this->role === 'fabric_vendor', fn () => $this->fabricVendor()->exists()),
        ];
    }
}
