<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One version of a review's content. Version 1 is what the customer first posted; later versions are corrections. */
class TailorReviewRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['review_id', 'version', 'stars', 'quality', 'communication', 'delivery_time', 'value_for_money', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
