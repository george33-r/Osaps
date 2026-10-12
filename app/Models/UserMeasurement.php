<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMeasurement extends Model
{
    protected $fillable = ['unit', 'values', 'notes', 'share_with_tailors'];

    protected function casts(): array
    {
        return ['values' => 'array', 'share_with_tailors' => 'boolean'];
    }
}
