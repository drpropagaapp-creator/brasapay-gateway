<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformUazapiSetting extends Model
{
    protected $fillable = [
        'is_active',
        'server_url',
        'admin_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'admin_token' => 'encrypted',
        ];
    }

    public static function instance(): self
    {
        return static::query()->firstOrCreate([], [
            'is_active' => false,
            'server_url' => null,
            'admin_token' => null,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->is_active
            && is_string($this->server_url) && trim($this->server_url) !== ''
            && is_string($this->admin_token) && trim($this->admin_token) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'is_active' => (bool) $this->is_active,
            'server_url' => (string) ($this->server_url ?? ''),
            'configured' => is_string($this->admin_token) && trim($this->admin_token) !== '',
            'docs_url' => (string) config('uazapi.docs_url'),
        ];
    }
}
