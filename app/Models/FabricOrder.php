<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FabricOrder extends Model
{
    protected $fillable = [];

    /** Only the buyer ever sees the delivery code, and only through FabricOrderResource. */
    protected $hidden = ['delivery_code'];

    protected function casts(): array
    {
        return [
            'express' => 'boolean',
            'subtotal_minor' => 'integer',
            'delivery_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'vendor_commission_minor' => 'integer',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'paid_out_at' => 'datetime',
            'dispute_opened_at' => 'datetime',
            'dispute_resolved_at' => 'datetime',
            'payout_approved_at' => 'datetime',
            'delivery_code_attempts' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FabricVendor::class, 'fabric_vendor_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AsoEbiGroup::class, 'aso_ebi_group_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FabricOrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** Refunds that are done or on their way: what the buyer is already getting back. */
    public function committedRefundMinor(): int
    {
        return (int) $this->refunds()->whereIn('status', ['pending', 'processed'])->sum('amount_minor');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FabricOrderEvent::class)->orderBy('id');
    }
}
