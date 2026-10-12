<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceOfferRound extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['author_role', 'author_id', 'action', 'unit_minor'];

    protected function casts(): array
    {
        return ['unit_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
