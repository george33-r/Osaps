<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's question for tailors. Its answer, once an admin publishes it, becomes a tip on the home page. */
class Question extends Model
{
    public const PENDING = 'pending';

    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    protected $fillable = ['asker_id', 'question', 'status', 'tailor_profile_id', 'answer', 'answered_at', 'story_id', 'hidden_reason', 'decided_by'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    public function tailorProfile(): BelongsTo
    {
        return $this->belongsTo(TailorProfile::class);
    }
}
