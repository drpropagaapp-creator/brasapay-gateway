<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionRecoveryStop extends Model
{
    public const REASON_REPLIED = 'replied';

    public const REASON_OPT_OUT = 'opt_out';

    public const REASON_PAID = 'paid';

    protected $fillable = [
        'tenant_id',
        'evolution_instance_id',
        'phone',
        'reason',
        'inbound_text',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(EvolutionInstance::class, 'evolution_instance_id');
    }

    public static function blocks(int $tenantId, string $phone, DateTimeInterface $startedAt): bool
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->where('created_at', '>=', $startedAt)
            ->exists();
    }
}
