<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = ['checkout_id', 'fabric_order_id', 'amount_minor', 'currency', 'status', 'reason', 'requested_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'attempts' => 'integer', 'processed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FabricOrder::class, 'fabric_order_id');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}
