<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Divergência encontrada pela conciliação (Brasa Treasury). Registro de observação:
 * nunca movimenta saldo; a correção é sempre uma decisão do admin, auditada.
 */
class TreasuryDiscrepancy extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_ATTENTION = 'attention';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'kind', 'severity', 'status', 'fingerprint', 'tenant_id', 'order_id', 'withdrawal_id',
        'amount_cents', 'details', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'amount_cents' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_INVESTIGATING], true);
    }
}
