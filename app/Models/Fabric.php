<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fabric extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'category', 'description', 'primary_color', 'colors', 'pattern', 'material',
        'unit', 'price_minor', 'stock_quantity', 'min_order_quantity', 'image_path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'price_minor' => 'integer',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FabricVendor::class, 'fabric_vendor_id');
    }

    public function availableQuantity(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }

    public function stockStatus(): string
    {
        $available = $this->availableQuantity();

        return match (true) {
            $available === 0 => 'out_of_stock',
            $available < 10 => 'low_stock',
            default => 'in_stock',
        };
    }

    public function scopeListed(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /**
     * Structured catalogue filters. Text search is a plain LIKE for now; a ranked or semantic
     * search should replace it only once it measurably improves results.
     *
     * @param  array<string,mixed>  $f
     */
    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['q'] ?? null, function (Builder $q, string $term) {
                // Bound parameter (no injection risk); wildcards in the term simply broaden the match.
                $like = '%'.mb_substr(trim($term), 0, 80).'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('material', 'like', $like));
            })
            ->when($f['category'] ?? null, fn (Builder $q, $c) => $q->whereIn('category', (array) $c))
            ->when($f['color'] ?? null, fn (Builder $q, $c) => $q->whereIn('primary_color', (array) $c))
            ->when(isset($f['min_price']), fn (Builder $q) => $q->where('price_minor', '>=', (int) $f['min_price']))
            ->when(isset($f['max_price']), fn (Builder $q) => $q->where('price_minor', '<=', (int) $f['max_price']))
            ->when($f['in_stock'] ?? false, fn (Builder $q) => $q->whereColumn('stock_quantity', '>', 'reserved_quantity'))
            ->when($f['city'] ?? null, fn (Builder $q, $city) => $q->whereHas('vendor', fn ($v) => $v->where('city', $city)));
    }
}
