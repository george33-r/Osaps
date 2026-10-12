<?php

namespace App\Models;

use App\Domain\Shop\PriceOfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceOffer extends Model
{
    // Status, prices and rounds are only ever set by ShopOfferService.
    protected $fillable = ['quantity', 'note'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'list_unit_minor' => 'integer',
            'unit_minor' => 'integer',
            'buyer_unit_minor' => 'integer',
            'vendor_unit_minor' => 'integer',
            'buyer_rounds' => 'integer',
            'vendor_rounds' => 'integer',
            'respond_by' => 'datetime',
            'hold_until' => 'datetime',
            'accepted_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FabricVendor::class, 'fabric_vendor_id');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(PriceOfferRound::class)->orderBy('id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, PriceOfferStatus::LIVE, true);
    }

    /** An agreed price the buyer can still check out with. */
    public function isUsable(): bool
    {
        return $this->status === PriceOfferStatus::ACCEPTED && $this->hold_until !== null && $this->hold_until->isFuture();
    }
}
