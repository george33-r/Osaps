<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TryOnRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'fabric_id', 'result'];
}
