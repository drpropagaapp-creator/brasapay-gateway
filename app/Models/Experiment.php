<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Teste A/B da landing do checkout (Brasa Experiments). */
class Experiment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_FINISHED = 'finished';

    protected $fillable = ['tenant_id', 'product_id', 'name', 'status', 'variants', 'started_at', 'ended_at', 'winner_key', 'created_by'];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
