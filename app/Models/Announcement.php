<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    public const AUDIENCES = ['tailors', 'customers', 'everyone'];

    protected $fillable = ['created_by', 'audience', 'subject', 'body', 'story_id', 'recipients', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
