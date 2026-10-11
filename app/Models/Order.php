<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    // Nothing here is mass-assignable from requests; orders are created only by NegotiationService.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'spec_snapshot' => 'array',
            'due_on' => 'date',
            'tailoring_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'service_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'tailor_commission_minor' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    /** The role a user plays on this order, or null if they are not a party to it. */
    public function roleOf(User $user): ?string
    {
        return match (true) {
            $user->id === $this->customer_id => 'customer',
            $user->id === $this->tailor_id => 'tailor',
            $user->isAdmin() => 'admin',
            default => null,
        };
    }
}
