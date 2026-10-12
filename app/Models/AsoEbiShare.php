<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsoEbiShare extends Model
{
    protected $fillable = ['yards', 'show_name'];

    protected function casts(): array
    {
        return ['yards' => 'integer', 'show_name' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AsoEbiGroup::class, 'aso_ebi_group_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_id');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}
