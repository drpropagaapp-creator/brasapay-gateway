<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryOptOut extends Model
{
    protected $fillable = ['tenant_id', 'email', 'channel'];

    public static function isOptedOut(int $tenantId, string $email, string $channel = 'email'): bool
    {
        return static::query()->where('tenant_id', $tenantId)->where('email', mb_strtolower(trim($email)))->where('channel', $channel)->exists();
    }
}
