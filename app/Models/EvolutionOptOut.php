<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionOptOut extends Model
{
    protected $fillable = [
        'tenant_id',
        'evolution_instance_id',
        'phone',
        'source',
        'inbound_text',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(EvolutionInstance::class, 'evolution_instance_id');
    }

    public static function isOptedOut(int $tenantId, string $phone): bool
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->exists();
    }
}
