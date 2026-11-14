<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo of the finished outfit. Stored privately; shown publicly only after an admin approves it. */
class TailorReviewPhoto extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['review_id', 'path', 'mime', 'size_bytes', 'status', 'decided_by', 'decided_at'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
