<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cost and reliability log for AI calls. Holds no prompt text and no personal data. */
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = ['user_id', 'purpose', 'provider', 'model', 'prompt_version', 'input_tokens', 'output_tokens', 'latency_ms', 'outcome'];
}
