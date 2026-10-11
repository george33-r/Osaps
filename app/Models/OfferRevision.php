<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only: revisions are history and are never updated or deleted individually. */
class OfferRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['author_role', 'author_id', 'amount_minor', 'delivery_days', 'note'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Offer revisions are immutable.'));
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
