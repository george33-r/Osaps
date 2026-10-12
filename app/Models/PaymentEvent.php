<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'event_key', 'reference', 'event', 'signature_valid', 'result', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'created_at' => 'datetime'];
    }
}
