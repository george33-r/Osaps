<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FabricMedia extends Model
{
    protected $table = 'fabric_media';

    protected $fillable = ['kind', 'path', 'mime', 'size_bytes', 'sort'];

    public function url(): string
    {
        return asset('uploads/'.$this->path);
    }
}
