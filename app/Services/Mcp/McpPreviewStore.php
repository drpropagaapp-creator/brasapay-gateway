<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class McpPreviewStore
{
    public const PREVIEW_TTL = 900;

    public const IDEMPOTENCY_TTL = 86400;

    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  list<array<string, mixed>>  $gates
     * @return array{preview_id: string, expires_at: string}
     */
    public function put(int $tenantId, array $operations, array $gates = []): array
    {
        $previewId = 'mcp_prev_'.Str::lower(Str::random(32));
        $expiresAt = now()->addSeconds(self::PREVIEW_TTL);
        $body = [
            'tenant_id' => $tenantId,
            'operations' => $operations,
            'gates' => $gates,
            'consumed_at' => null,
            'result' => null,
        ];
        $body['mac'] = $this->mac($tenantId, $previewId, $operations);

        Cache::put($this->previewKey($tenantId, $previewId), $body, $expiresAt);

        return [
            'preview_id' => $previewId,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $tenantId, string $previewId): ?array
    {
        $previewId = trim($previewId);
        if ($previewId === '' || ! preg_match('/^mcp_prev_[a-z0-9]{32}$/', $previewId)) {
            return null;
        }

        $body = Cache::get($this->previewKey($tenantId, $previewId));
        if (! is_array($body) || (int) ($body['tenant_id'] ?? 0) !== $tenantId) {
            return null;
        }

        $operations = is_array($body['operations'] ?? null) ? $body['operations'] : [];
        $expected = $this->mac($tenantId, $previewId, $operations);
        $given = is_string($body['mac'] ?? null) ? $body['mac'] : '';
        if ($given === '' || ! hash_equals($expected, $given)) {
            return null;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markConsumed(int $tenantId, string $previewId, array $result): void
    {
        $body = $this->get($tenantId, $previewId);
        if ($body === null) {
            return;
        }

        $body['consumed_at'] = now()->toIso8601String();
        $body['result'] = $result;
        Cache::put($this->previewKey($tenantId, $previewId), $body, now()->addSeconds(self::PREVIEW_TTL));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIdempotency(int $tenantId, string $key): ?array
    {
        $normalized = $this->normalizeIdempotencyKey($key);
        if ($normalized === null) {
            return null;
        }

        $hit = Cache::get($this->idempotencyKey($tenantId, $normalized));

        return is_array($hit) ? $hit : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function putIdempotency(int $tenantId, string $key, array $result): void
    {
        $normalized = $this->normalizeIdempotencyKey($key);
        if ($normalized === null) {
            return;
        }

        Cache::put(
            $this->idempotencyKey($tenantId, $normalized),
            $result,
            now()->addSeconds(self::IDEMPOTENCY_TTL)
        );
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function mac(int $tenantId, string $previewId, array $operations): string
    {
        $canonical = json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $tenantId.'|'.$previewId.'|'.$canonical, (string) config('app.key'));
    }

    private function previewKey(int $tenantId, string $previewId): string
    {
        return 'mcp:preview:'.$tenantId.':'.$previewId;
    }

    private function idempotencyKey(int $tenantId, string $key): string
    {
        return 'mcp:idem:'.$tenantId.':'.hash('sha256', $key);
    }

    private function normalizeIdempotencyKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 128) {
            return null;
        }

        return $key;
    }
}
