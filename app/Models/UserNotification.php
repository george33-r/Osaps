<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'kind', 'title', 'body', 'link'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
