<?php

namespace App\Services\Mcp;

use App\Models\McpCredential;

class McpCredentialService
{
    /**
     * @return array{credential: McpCredential, token: string}
     */
    public function activate(int $tenantId): array
    {
        $existing = McpCredential::query()->forTenant($tenantId)->first();
        if ($existing !== null) {
            return $this->rotate($existing);
        }

        $parts = McpCredential::issueTokenParts();
        $credential = McpCredential::query()->create([
            'tenant_id' => $tenantId,
            'public_id' => $parts['public_id'],
            'token_hash' => McpCredential::hashToken($parts['plain']),
            'token_suffix' => $parts['suffix'],
            'is_active' => true,
        ]);

        return [
            'credential' => $credential,
            'token' => $parts['plain'],
        ];
    }

    /**
     * @return array{credential: McpCredential, token: string}
     */
    public function rotate(McpCredential $credential): array
    {
        $parts = McpCredential::issueTokenParts();
        $credential->forceFill([
            'public_id' => $parts['public_id'],
            'token_hash' => McpCredential::hashToken($parts['plain']),
            'token_suffix' => $parts['suffix'],
            'is_active' => true,
            'last_used_at' => null,
        ])->save();

        return [
            'credential' => $credential->fresh() ?? $credential,
            'token' => $parts['plain'],
        ];
    }

    public function findActiveByPlainToken(string $plain): ?McpCredential
    {
        $publicId = McpCredential::parsePublicId($plain);
        if ($publicId === null) {
            return null;
        }

        $credential = McpCredential::query()
            ->active()
            ->where('public_id', $publicId)
            ->first();

        if ($credential === null || ! $credential->verifyToken($plain)) {
            return null;
        }

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?McpCredential $credential, string $serverUrl): array
    {
        return [
            'connected' => $credential !== null && $credential->is_active,
            'public_id' => $credential?->public_id,
            'masked_token' => $credential?->maskedToken(),
            'created_at' => $credential?->created_at?->toIso8601String(),
            'last_used_at' => $credential?->last_used_at?->toIso8601String(),
            'server_url' => $serverUrl,
        ];
    }
}
