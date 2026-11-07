<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorVerificationDocument extends Model
{
    protected $fillable = ['tailor_profile_id', 'kind', 'path', 'mime', 'size_bytes', 'status'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TailorProfile::class, 'tailor_profile_id');
    }
}
