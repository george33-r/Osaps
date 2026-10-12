<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FabricVendor extends Model
{
    use HasFactory;

    protected $fillable = ['business_name', 'city', 'market', 'phone', 'whatsapp', 'contact_note', 'bank_name', 'bank_account_number', 'bank_account_name'];

    /** Contact details are never part of public listings. */
    protected $hidden = ['phone', 'whatsapp', 'bank_account_number'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'verified_at' => 'datetime', 'bank_account_number' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function liveSessions(): HasMany
    {
        return $this->hasMany(LiveSession::class);
    }

    public function fabrics(): HasMany
    {
        return $this->hasMany(Fabric::class);
    }
}
