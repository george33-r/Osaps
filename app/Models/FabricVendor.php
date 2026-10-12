<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FabricVendor extends Model
{
    use HasFactory;

    protected $fillable = ['business_name', 'city', 'market', 'phone', 'whatsapp', 'contact_note'];

    /** Contact details are never part of public listings. */
    protected $hidden = ['phone', 'whatsapp'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fabrics(): HasMany
    {
        return $this->hasMany(Fabric::class);
    }
}
