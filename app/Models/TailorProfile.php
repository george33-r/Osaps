<?php

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_name', 'slug', 'bio', 'city', 'service_cities', 'specialties', 'portfolio_tags',
        'weekly_capacity', 'starting_price_minor', 'accepting_work',
    ];

    protected function casts(): array
    {
        return [
            'service_cities' => 'array',
            'specialties' => 'array',
            'portfolio_tags' => 'array',
            'accepting_work' => 'boolean',
            'is_demo' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function scopeAcceptingWork(Builder $q): Builder
    {
        return $q->where('accepting_work', true);
    }

    /**
     * Track record computed from real orders only — never self-reported or seeded.
     *
     * @return array{completed_orders:int,on_time_orders:int,upheld_complaints:int,open_orders:int}
     */
    public function trackRecord(): array
    {
        $orders = Order::query()->where('tailor_id', $this->user_id);

        $completed = (clone $orders)->where('status', OrderStatus::COMPLETED);

        return [
            'completed_orders' => (clone $completed)->count(),
            'on_time_orders' => (clone $completed)
                ->whereNotNull('due_on')
                ->whereExists(fn ($q) => $q->from('order_events')
                    ->whereColumn('order_events.order_id', 'orders.id')
                    ->where('order_events.to_status', OrderStatus::DELIVERED)
                    ->whereRaw('date(order_events.created_at) <= orders.due_on'))
                ->count(),
            // Complaints count only once an admin upholds them (dispute module, later phase).
            'upheld_complaints' => 0,
            'open_orders' => (clone $orders)->whereNotIn('status', [OrderStatus::COMPLETED, OrderStatus::CANCELLED])->count(),
        ];
    }
}
