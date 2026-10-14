<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of what each admin did. Never updated or deleted by the app. */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'subject_type', 'subject_id', 'note', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @param array<string,mixed>|null $data */
    public static function record(User $admin, string $action, Model $subject, ?string $note = null, ?array $data = null): self
    {
        return self::query()->create([
            'admin_id' => $admin->id, 'action' => $action,
            'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(),
            'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'data' => $data,
        ]);
    }
}
