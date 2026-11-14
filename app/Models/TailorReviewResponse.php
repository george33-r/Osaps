<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailorReviewResponse extends Model
{
    public $timestamps = false;

    protected $fillable = ['review_id', 'tailor_id', 'responded_to_version', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
