<?php

namespace App\Services\Xflow;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP Xflow (Payment Hub) — Basic auth pk_* + sk_*.
 */
class XflowHttpClient
{
    public const BASE_URL = 'https://app.xflowpayments.com/api/v1';

    private const REQUEST_TIMEOUT_SECONDS = 25;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     */
    public function request(
        array $credentials,
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        ?int $timeoutSeconds = null,
    ): Response {
        [$publicKey, $secretKey] = $this->keys($credentials);

        $pending = Http::timeout($timeoutSeconds ?? self::REQUEST_TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withBasicAuth($publicKey, $secretKey)
            ->acceptJson()
            ->asJson();

        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        $url = rtrim(self::BASE_URL, '/').'/'.ltrim($path, '/');
        $verb = strtoupper($method);

        $response = match ($verb) {
            'GET' => $pending->get($url, is_array($json) ? $json : []),
            'POST' => $pending->post($url, $json ?? []),
            'PATCH' => $pending->patch($url, $json ?? []),
            'DELETE' => $pending->delete($url),
            default => throw new RuntimeException('Xflow: método HTTP inválido.'),
        };

        $requestId = $this->requestId($response);
        if (! $response->successful()) {
            Log::warning('Xflow HTTP error', [
                'method' => $verb,
                'path' => $path,
                'status' => $response->status(),
                'request_id' => $requestId,
                'error' => $this->errorMessage($response),
            ]);
        } elseif ($requestId !== '') {
            Log::debug('Xflow HTTP ok', [
                'method' => $verb,
                'path' => $path,
                'status' => $response->status(),
                'request_id' => $requestId,
            ]);
        }

        return $response;
    }

    public function errorCode(Response $response): ?string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }
        $code = $json['error'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return strtolower(trim($code));
    }

    public function errorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $message = $json['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                $code = $json['error'] ?? null;
                $suffix = is_string($code) && $code !== '' ? ' ('.$code.')' : '';
                $requestId = $this->requestId($response);
                if ($requestId !== '') {
                    $suffix .= ' ['.$requestId.']';
                }

                return trim($message).$suffix;
            }
        }

        $body = trim($response->body());
        if ($body !== '') {
            return mb_substr($body, 0, 280);
        }

        return 'HTTP '.$response->status();
    }

    public function requestId(Response $response): string
    {
        $header = $response->header('x-request-id');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }
        $json = $response->json();
        if (is_array($json) && is_string($json['request_id'] ?? null) && $json['request_id'] !== '') {
            return $json['request_id'];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public static function unwrapObject(array $json): array
    {
        $data = $json['data'] ?? null;
        if (is_array($data) && $data !== [] && ! array_is_list($data)) {
            return $data;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{0: string, 1: string}
     */
    private function keys(array $credentials): array
    {
        $publicKey = trim((string) ($credentials['public_key'] ?? ''));
        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));
        if ($publicKey === '' || $secretKey === '') {
            throw new RuntimeException('Xflow: informe a chave pública (pk_…) e o segredo (sk_…).');
        }

        return [$publicKey, $secretKey];
    }
}
