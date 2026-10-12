<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FabricOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['fabric_id', 'price_offer_id', 'name', 'unit', 'quantity', 'list_unit_minor', 'unit_minor', 'line_total_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'list_unit_minor' => 'integer', 'unit_minor' => 'integer', 'line_total_minor' => 'integer'];
    }

    public function fabricOrder(): BelongsTo
    {
        return $this->belongsTo(FabricOrder::class);
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }
}
