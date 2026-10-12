<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsoEbiGroup extends Model
{
    protected $fillable = ['title', 'message'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'deadline_at' => 'datetime',
            'yards_per_guest' => 'integer',
            'target_guests' => 'integer',
            'deal_unit_minor' => 'integer',
            'deal_quantity' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(AsoEbiShare::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->deadline_at->isFuture();
    }
}
