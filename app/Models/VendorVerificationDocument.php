<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorVerificationDocument extends Model
{
    protected $fillable = ['fabric_vendor_id', 'kind', 'path', 'mime', 'size_bytes', 'status'];

    /** The stored file name stays private; the API gives a file URL only to admins. */
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FabricVendor::class, 'fabric_vendor_id');
    }
}
