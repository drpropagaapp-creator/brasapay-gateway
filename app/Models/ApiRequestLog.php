<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma chamada à API pública (sem corpo nem cabeçalhos): rota, status, tempo e erro resumido. */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'api_application_id', 'api_key_id', 'environment', 'method', 'route',
        'status', 'duration_ms', 'request_id', 'error_message', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
