<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per week: the tailor featured as "Tailor of the week". Picked by rule, or set by an admin. */
class TailorFeature extends Model
{
    public const AUTO = 'auto';

    public const ADMIN = 'admin';

    protected $fillable = ['week_start', 'tailor_profile_id', 'source', 'reason', 'chosen_by'];

    protected function casts(): array
    {
        return ['week_start' => 'date'];
    }

    public function tailorProfile(): BelongsTo
    {
        return $this->belongsTo(TailorProfile::class);
    }
}
