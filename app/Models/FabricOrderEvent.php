<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FabricOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'actor_role', 'from_status', 'to_status', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
