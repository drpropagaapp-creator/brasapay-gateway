<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class McpCredential extends Model
{
    public const TOKEN_PREFIX = 'mcp_live_';

    protected $fillable = [
        'tenant_id',
        'public_id',
        'token_hash',
        'token_suffix',
        'is_active',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array{public_id: string, plain: string, suffix: string}
     */
    public static function issueTokenParts(): array
    {
        do {
            $publicId = Str::lower(Str::random(16));
        } while (static::query()->where('public_id', $publicId)->exists());

        $secret = Str::random(32);
        $plain = self::TOKEN_PREFIX.$publicId.'_'.$secret;

        return [
            'public_id' => $publicId,
            'plain' => $plain,
            'suffix' => substr($plain, -4),
        ];
    }

    public static function hashToken(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function parsePublicId(string $plain): ?string
    {
        if (! preg_match('/^mcp_live_([a-z0-9]{16})_[A-Za-z0-9]{32,}$/', $plain, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function verifyToken(string $plain): bool
    {
        return is_string($this->token_hash)
            && $this->token_hash !== ''
            && password_verify($plain, $this->token_hash);
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function maskedToken(): string
    {
        return self::TOKEN_PREFIX.substr($this->public_id, 0, 4).'…'.$this->token_suffix;
    }
}
