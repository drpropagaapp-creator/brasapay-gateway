<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Conta white-label (Brasa Enterprise). Ativada e suspensa só pelo admin da plataforma. */
class EnterpriseAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const DEFAULT_MAX_DOMAINS = 3;

    protected $fillable = ['tenant_id', 'status', 'display_name', 'features', 'limits', 'suspension_reason', 'enabled_by'];

    protected function casts(): array
    {
        return ['features' => 'array', 'limits' => 'array'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function maxDomains(): int
    {
        return max(0, min(20, (int) ($this->limits['max_domains'] ?? self::DEFAULT_MAX_DOMAINS)));
    }

    /** @return list<string> itens do menu do seller escondidos por configuração do admin */
    public function hiddenNav(): array
    {
        return array_values(array_filter((array) ($this->features['hidden_nav'] ?? []), 'is_string'));
    }

    public static function activeFor(int $tenantId): ?self
    {
        return self::query()->where('tenant_id', $tenantId)->where('status', self::STATUS_ACTIVE)->first();
    }
}
