<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorySave extends Model
{
    protected $fillable = ['user_id', 'story_id'];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
