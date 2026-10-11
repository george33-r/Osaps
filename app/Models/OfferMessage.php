<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A message between the two parties to one offer. Append-only: messages are not edited or deleted. */
class OfferMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['author_id', 'author_role', 'body'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Messages are immutable.'));
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
