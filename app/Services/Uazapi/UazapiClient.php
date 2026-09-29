<?php

namespace App\Services\Uazapi;

use App\Exceptions\UazapiRequestException;
use App\Models\UazapiInstance;
use App\Models\PlatformUazapiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UazapiClient
{
    private ?string $overrideServerUrl = null;

    public function settings(): PlatformUazapiSetting
    {
        return PlatformUazapiSetting::instance();
    }

    public function using(UazapiInstance $instance): self
    {
        $copy = clone $this;
        $copy->overrideServerUrl = $this->normalizeServerUrl($instance->server_url);

        return $copy;
    }

    public function usingServer(?string $url): self
    {
        $copy = clone $this;
        $copy->overrideServerUrl = $this->normalizeServerUrl($url);

        return $copy;
    }

    public function normalizeServerUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $url = rtrim($url, '/');
        $url = (string) preg_replace('#/(instance|api|v1|v2)$#i', '', $url);

        return rtrim($url, '/');
    }

    public function normalizeToken(?string $token): string
    {
        $token = trim((string) $token);
        $token = preg_replace('/^\xEF\xBB\xBF/', '', $token) ?? $token;
        $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $token) ?? $token;
        $token = preg_replace('/^Bearer\s+/i', '', trim($token)) ?? $token;
        $token = trim($token, " \t\n\r\0\x0B\"'");

        return trim($token);
    }

    public function serverUrl(?PlatformUazapiSetting $settings = null): string
    {
        if (is_string($this->overrideServerUrl) && $this->overrideServerUrl !== '') {
            return $this->overrideServerUrl;
        }

        $settings ??= $this->settings();

        return $this->normalizeServerUrl($settings->server_url);
    }

    public function normalizePhone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            return $digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '55'.$digits;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function adminRequest(string $method, string $path, array $body = [], bool $isMutation = true): array
    {
        $settings = $this->settings();
        if (! $settings->isConfigured()) {
            throw new UazapiRequestException('Servidor WhatsApp da plataforma não está configurado.', 0, false);
        }

        return $this->call(
            $method,
            $path,
            ['admintoken' => (string) $settings->admin_token],
            $body,
            $isMutation
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function instanceRequest(string $token, string $method, string $path, array $body = [], bool $isMutation = true): array
    {
        $token = $this->normalizeToken($token);
        if ($token === '') {
            throw new UazapiRequestException('Instância WhatsApp sem token.', 0, false);
        }

        return $this->call(
            $method,
            $path,
            ['token' => $this->normalizeToken($token)],
            $body,
            $isMutation
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function listInstancesWithAdminToken(string $adminToken): array
    {
        return $this->call(
            'GET',
            '/instance/all',
            ['admintoken' => $this->normalizeToken($adminToken)],
            [],
            false
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createInstance(string $name, ?string $adminField01 = null): array
    {
        $payload = ['name' => $name];
        if ($adminField01 !== null && $adminField01 !== '') {
            $payload['adminField01'] = $adminField01;
        }

        return $this->adminRequest('POST', '/instance/create', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(string $token): array
    {
        return $this->instanceRequest($token, 'POST', '/instance/connect', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $token): array
    {
        return $this->instanceRequest($token, 'GET', '/instance/status', [], false);
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(string $token): array
    {
        return $this->instanceRequest($token, 'POST', '/instance/disconnect', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function configureWebhook(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/webhook', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendText(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/send/text', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMenu(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/send/menu', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMedia(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/send/media', $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLabels(string $token): array
    {
        $response = $this->instanceRequest($token, 'GET', '/labels', [], false);
        if (array_is_list($response)) {
            /** @var array<int, array<string, mixed>> $response */
            return $response;
        }

        $items = $response['labels'] ?? $response['data'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editLabel(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/label/edit', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function setChatLabels(string $token, array $payload): array
    {
        return $this->instanceRequest($token, 'POST', '/chat/labels', $payload);
    }

    /**
     * @param  list<string>  $numbers
     * @return array<int, array<string, mixed>>
     */
    public function checkChats(string $token, array $numbers): array
    {
        $response = $this->instanceRequest($token, 'POST', '/chat/check', [
            'numbers' => $numbers,
        ], false);

        if (array_is_list($response)) {
            /** @var array<int, array<string, mixed>> $response */
            return $response;
        }

        $items = $response['data'] ?? $response['result'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function messageLimits(string $token): array
    {
        return $this->instanceRequest($token, 'GET', '/instance/wa_messages_limits', [], false);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $headers, array $body, bool $isMutation): array
    {
        $base = $this->serverUrl();
        if ($base === '') {
            throw new UazapiRequestException('Server URL da uazapi não configurada.', 0, false);
        }

        $url = $base.$path;
        $pending = Http::timeout((int) config('uazapi.http_timeout', 20))
            ->acceptJson()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false]);

        try {
            $method = strtoupper($method);
            $response = match ($method) {
                'GET' => $body === [] ? $pending->get($url) : $pending->get($url, $body),
                'POST' => $pending->post($url, $body),
                'PUT' => $pending->put($url, $body),
                'DELETE' => $pending->delete($url, $body),
                default => throw new UazapiRequestException('Método HTTP não suportado.', 0, false),
            };
        } catch (ConnectionException $e) {
            throw new UazapiRequestException(
                'Falha de conexão com a uazapi: '.$e->getMessage(),
                0,
                ! $isMutation
            );
        }

        return $this->decode($response, $isMutation);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, bool $isMutation): array
    {
        $status = $response->status();
        $json = $response->json();
        $body = is_array($json) ? $json : [];

        if ($response->successful()) {
            return $body;
        }

        if (in_array($status, [301, 302, 307, 308], true)) {
            throw new UazapiRequestException(
                'A Server URL redirecionou. Use o endereço HTTPS da API (ex.: https://seu-subdominio.uazapi.com), sem caminho extra.',
                $status,
                false
            );
        }

        $message = $this->errorMessage($body, $response->body(), $status);
        $retryable = in_array($status, [429, 503], true) || ($status >= 500 && ! $isMutation);

        throw new UazapiRequestException($message, $status, $retryable);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body, string $raw, int $status): string
    {
        foreach (['error', 'message', 'info'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '') {
                return 'uazapi HTTP '.$status.': '.$body[$key];
            }
        }

        $snippet = mb_substr(trim($raw), 0, 240);

        return 'uazapi HTTP '.$status.($snippet !== '' ? ': '.$snippet : '');
    }
}
