<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Domínio próprio do checkout white-label. Só serve tráfego com status active (DNS e HTTPS conferidos). */
class EnterpriseDomain extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REMOVED = 'removed';

    /** Falhas seguidas na reverificação diária antes de desativar o domínio. */
    public const MAX_FAILURES = 3;

    protected $fillable = [
        'tenant_id', 'host', 'verification_token', 'status', 'dns_verified_at', 'https_verified_at',
        'last_checked_at', 'consecutive_failures', 'last_error',
    ];

    protected function casts(): array
    {
        return ['dns_verified_at' => 'datetime', 'https_verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    public function txtName(): string
    {
        return '_brasapay-verify.'.$this->host;
    }

    public function txtValue(): string
    {
        return 'brasapay-verify='.$this->verification_token;
    }
}
