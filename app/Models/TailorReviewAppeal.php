<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer asks for a hidden review to be restored. Only one open appeal per review. */
class TailorReviewAppeal extends Model
{
    protected $fillable = ['review_id', 'customer_id', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
