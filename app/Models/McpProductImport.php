<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpProductImport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'platform_key_id',
        'actor_user_id',
        'source_url',
        'manifest',
        'status',
        'progress',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'progress' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'import_id' => $this->uuid,
            'tenant_id' => (int) $this->tenant_id,
            'status' => $this->status,
            'progresso' => $this->progress ?? [],
            'resultado' => $this->result,
            'erro' => $this->error,
            'criado_em' => $this->created_at?->toIso8601String(),
            'iniciado_em' => $this->started_at?->toIso8601String(),
            'concluido_em' => $this->finished_at?->toIso8601String(),
        ];
    }
}
