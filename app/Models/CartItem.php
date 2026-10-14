<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signed-in shopper's cart line, so the cart follows them across devices. Prices are never stored here. */
class CartItem extends Model
{
    protected $fillable = ['user_id', 'fabric_id', 'quantity', 'price_offer_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(PriceOffer::class, 'price_offer_id');
    }
}
