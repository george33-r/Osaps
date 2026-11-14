<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment attempt for a tailor order. The order is marked paid only when Paystack confirms it.
 */
class OrderPayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    protected $fillable = ['order_id', 'reference', 'amount_minor', 'currency', 'status', 'amount_paid_minor', 'authorization_url', 'paid_at'];

    protected $hidden = ['authorization_url'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'amount_paid_minor' => 'integer', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
