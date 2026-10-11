<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_TAILOR = 'tailor';
    public const ROLE_FABRIC_VENDOR = 'fabric_vendor';
    public const ROLE_ADMIN = 'admin';

    /** Roles a person may choose at sign-up. Admin is never self-assignable. */
    public const SELF_SERVICE_ROLES = [self::ROLE_CUSTOMER, self::ROLE_TAILOR, self::ROLE_FABRIC_VENDOR];

    protected $fillable = ['name', 'email', 'phone', 'password', 'city'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isTailor(): bool
    {
        return $this->role === self::ROLE_TAILOR;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function tailorProfile(): HasOne
    {
        return $this->hasOne(TailorProfile::class);
    }

    public function fabricVendor(): HasOne
    {
        return $this->hasOne(FabricVendor::class);
    }

    public function jobRequests(): HasMany
    {
        return $this->hasMany(JobRequest::class, 'customer_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'tailor_id');
    }
}
