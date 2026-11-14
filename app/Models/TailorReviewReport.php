<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailorReviewReport extends Model
{
    protected $fillable = ['review_id', 'reporter_id', 'reason', 'details', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
