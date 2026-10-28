<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id', 'tenant_id', 'role', 'content', 'tools_used', 'model',
        'input_tokens', 'output_tokens', 'cost_micros', 'status', 'duration_ms',
    ];

    protected function casts(): array
    {
        return ['tools_used' => 'array', 'cost_micros' => 'integer'];
    }
}
