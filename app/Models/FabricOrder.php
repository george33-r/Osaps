<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FabricOrder extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'delivery_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'vendor_commission_minor' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(FabricOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FabricOrderEvent::class)->orderBy('id');
    }
}
