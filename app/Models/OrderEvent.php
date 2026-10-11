<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only order audit trail. */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'actor_role', 'from_status', 'to_status', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order events are immutable.'));
        static::deleting(fn () => throw new LogicException('Order events are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
