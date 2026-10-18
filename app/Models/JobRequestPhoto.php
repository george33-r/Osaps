<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRequestPhoto extends Model
{
    protected $fillable = ['path', 'mime', 'size_bytes', 'caption', 'sort'];

    protected $hidden = ['path'];

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    /** Served through the API, never straight from the disk, so only the right people see it. */
    public function url(): string
    {
        return url("/api/v1/design-photos/{$this->id}");
    }
}
