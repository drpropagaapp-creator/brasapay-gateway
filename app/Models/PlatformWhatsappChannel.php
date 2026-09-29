<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlatformWhatsappChannel extends Model
{
    public const PROVIDER_UAZAPI = 'uazapi';

    public const PROVIDER_EVOLUTION = 'evolution';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_HIBERNATED = 'hibernated';

    protected $fillable = [
        'provider',
        'server_url',
        'instance_name',
        'instance_token',
        'webhook_secret',
        'status',
        'phone',
        'profile_name',
        'qrcode',
        'paircode',
        'is_active',
        'last_error',
        'connected_at',
        'webhook_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'instance_token' => 'encrypted',
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            'webhook_synced_at' => 'datetime',
        ];
    }

    public static function providers(): array
    {
        return [self::PROVIDER_UAZAPI, self::PROVIDER_EVOLUTION];
    }

    public static function current(): self
    {
        $channel = static::query()->orderBy('id')->first();
        if ($channel) {
            return $channel;
        }

        $channel = new static;
        $channel->provider = self::PROVIDER_UAZAPI;
        $channel->webhook_secret = Str::lower(Str::random(48));
        $channel->status = self::STATUS_DISCONNECTED;
        $channel->is_active = true;
        $channel->save();

        PlatformWhatsappTemplate::ensureDefaults();

        return $channel;
    }

    public function isEvolution(): bool
    {
        return $this->provider === self::PROVIDER_EVOLUTION;
    }

    public function hasCredentials(): bool
    {
        $hasUrl = is_string($this->server_url) && trim($this->server_url) !== '';
        $hasToken = is_string($this->instance_token) && trim($this->instance_token) !== '';
        if (! $hasUrl || ! $hasToken) {
            return false;
        }

        if ($this->isEvolution()) {
            return is_string($this->instance_name) && trim($this->instance_name) !== '';
        }

        return true;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->hasCredentials();
    }

    public function canSend(): bool
    {
        return $this->is_active && $this->isConnected();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeQr = true): array
    {
        $connecting = in_array($this->status, [self::STATUS_CONNECTING, self::STATUS_DISCONNECTED], true);

        return [
            'provider' => $this->provider,
            'status' => $this->status,
            'phone' => $this->phone,
            'profile_name' => $this->profile_name,
            'qrcode' => $includeQr && $connecting ? $this->qrcode : null,
            'paircode' => $includeQr && $connecting ? $this->paircode : null,
            'server_url' => (string) ($this->server_url ?? ''),
            'instance_name' => (string) ($this->instance_name ?? ''),
            'has_token' => is_string($this->instance_token) && trim($this->instance_token) !== '',
            'has_credentials' => $this->hasCredentials(),
            'is_active' => (bool) $this->is_active,
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'connected' => $this->isConnected(),
            'can_send' => $this->canSend(),
        ];
    }
}
