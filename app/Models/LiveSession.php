<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveSession extends Model
{
    protected $fillable = ['title', 'platform', 'url', 'starts_at', 'ends_at', 'fabric_ids'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'fabric_ids' => 'array'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FabricVendor::class, 'fabric_vendor_id');
    }

    /** Live from the start time until the end time, or for two hours if no end was given. */
    public function isLive(): bool
    {
        $end = $this->ends_at ?? $this->starts_at->copy()->addHours(2);

        return $this->starts_at->isPast() && $end->isFuture();
    }
}
