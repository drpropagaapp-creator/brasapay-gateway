<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Chave fixa de integração da plataforma para sistemas (ex.: MetaTrack) usarem o MCP
 * sem login interativo. Age em nome do admin que a criou; escopo "agency" = leitura e criação,
 * sem ações de dinheiro, KYC ou MED.
 */
class McpPlatformKey extends Model
{
    public const TOKEN_PREFIX = 'mcp_pk_';

    public const SCOPE_AGENCY = 'agency';

    protected $fillable = [
        'name',
        'public_id',
        'token_hash',
        'token_suffix',
        'scope',
        'created_by_user_id',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array{model: self, plain: string}
     */
    public static function issue(string $name, User $admin, string $scope = self::SCOPE_AGENCY): array
    {
        do {
            $publicId = Str::lower(Str::random(16));
        } while (static::query()->where('public_id', $publicId)->exists());

        $plain = self::TOKEN_PREFIX.$publicId.'_'.Str::random(40);

        $model = static::query()->create([
            'name' => $name,
            'public_id' => $publicId,
            'token_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'token_suffix' => substr($plain, -4),
            'scope' => $scope,
            'created_by_user_id' => $admin->id,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    public static function findActiveByPlain(string $plain): ?self
    {
        if (! preg_match('/^mcp_pk_([a-z0-9]{16})_[A-Za-z0-9]{40,}$/', $plain, $m)) {
            return null;
        }

        $row = static::query()->where('public_id', $m[1])->whereNull('revoked_at')->first();
        if ($row === null || ! password_verify($plain, (string) $row->token_hash)) {
            return null;
        }

        return $row;
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function maskedToken(): string
    {
        return self::TOKEN_PREFIX.substr((string) $this->public_id, 0, 4).'…'.$this->token_suffix;
    }
}
