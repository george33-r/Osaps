<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    /** Money arrived but the goods could not be supplied: an admin must refund. */
    public const NEEDS_REFUND = 'needs_refund';

    protected $fillable = [];

    protected $hidden = ['authorization_url'];

    protected function casts(): array
    {
        return [
            'contact' => 'array',
            'address' => 'array',
            'subtotal_minor' => 'integer',
            'delivery_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FabricOrder::class);
    }
}
