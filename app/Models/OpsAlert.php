<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Alerta operacional do Command Center (derivado de números reais, com histórico). */
class OpsAlert extends Model
{
    public const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'attention' => 2, 'info' => 3];

    protected $fillable = [
        'key', 'severity', 'area', 'title', 'hint', 'href', 'status', 'first_seen_at', 'last_seen_at',
        'resolved_at', 'acknowledged_by', 'acknowledged_at', 'ack_note',
    ];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }
}
