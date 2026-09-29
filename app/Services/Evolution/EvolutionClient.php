<?php

namespace App\Services\Evolution;

use App\Exceptions\EvolutionRequestException;
use App\Models\EvolutionInstance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EvolutionClient
{
    private ?string $overrideServerUrl = null;

    public function using(EvolutionInstance $instance): self
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

        return trim($token, " \t\n\r\0\x0B\"'");
    }

    public function normalizeInstanceName(?string $name): string
    {
        return trim((string) $name);
    }

    public function serverUrl(): string
    {
        return is_string($this->overrideServerUrl) ? $this->overrideServerUrl : '';
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
     * @return array<string, mixed>
     */
    public function connect(string $token, string $instanceName): array
    {
        return $this->request($token, 'GET', '/instance/connect/'.$this->encodeName($instanceName), [], false);
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionState(string $token, string $instanceName): array
    {
        return $this->request($token, 'GET', '/instance/connectionState/'.$this->encodeName($instanceName), [], false);
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(string $token, string $instanceName): array
    {
        return $this->request($token, 'DELETE', '/instance/logout/'.$this->encodeName($instanceName));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function setWebhook(string $token, string $instanceName, array $payload): array
    {
        $path = '/webhook/set/'.$this->encodeName($instanceName);

        try {
            return $this->request($token, 'POST', $path, ['webhook' => $payload]);
        } catch (EvolutionRequestException $e) {
            if (! in_array($e->status, [400, 422], true)) {
                throw $e;
            }

            return $this->request($token, 'POST', $path, $payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $token, string $instanceName, string $number, string $text): array
    {
        $path = '/message/sendText/'.$this->encodeName($instanceName);
        $payload = [
            'number' => $number,
            'text' => $text,
            'textMessage' => ['text' => $text],
            'linkPreview' => true,
        ];

        try {
            return $this->request($token, 'POST', $path, $payload);
        } catch (EvolutionRequestException $e) {
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->request($token, 'POST', $path, [
                'number' => $number,
                'textMessage' => ['text' => $text],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sendImage(string $token, string $instanceName, string $number, string $mediaUrl): array
    {
        return $this->request($token, 'POST', '/message/sendMedia/'.$this->encodeName($instanceName), [
            'number' => $number,
            'mediatype' => 'image',
            'media' => $mediaUrl,
        ]);
    }

    /**
     * @param  list<string>  $numbers
     * @return array<int, array<string, mixed>>
     */
    public function checkNumbers(string $token, string $instanceName, array $numbers): array
    {
        $response = $this->request($token, 'POST', '/chat/whatsappNumbers/'.$this->encodeName($instanceName), [
            'numbers' => $numbers,
        ], false);

        $items = $response['numbers'] ?? $response;
        if (! is_array($items)) {
            return [];
        }

        return array_is_list($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $token, string $method, string $path, array $body = [], bool $isMutation = true): array
    {
        $token = $this->normalizeToken($token);
        if ($token === '') {
            throw new EvolutionRequestException('Instância Evolution sem token.', 0, false);
        }

        $base = $this->serverUrl();
        if ($base === '') {
            throw new EvolutionRequestException('Server URL da Evolution API não configurada.', 0, false);
        }

        $url = $base.$path;
        $pending = Http::timeout((int) config('evolution.http_timeout', 20))
            ->acceptJson()
            ->withHeaders(['apikey' => $token])
            ->withOptions(['allow_redirects' => false]);

        try {
            $method = strtoupper($method);
            $response = match ($method) {
                'GET' => $body === [] ? $pending->get($url) : $pending->get($url, $body),
                'POST' => $pending->post($url, $body),
                'PUT' => $pending->put($url, $body),
                'DELETE' => $pending->delete($url, $body),
                default => throw new EvolutionRequestException('Método HTTP não suportado.', 0, false),
            };
        } catch (ConnectionException $e) {
            throw new EvolutionRequestException(
                'Falha de conexão com a Evolution API: '.$e->getMessage(),
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
            throw new EvolutionRequestException(
                'A Server URL redirecionou. Use o endereço HTTPS da Evolution API, sem caminho extra.',
                $status,
                false
            );
        }

        $message = $this->errorMessage($body, $response->body(), $status);
        $retryable = in_array($status, [429, 503], true) || ($status >= 500 && ! $isMutation);

        throw new EvolutionRequestException($message, $status, $retryable);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body, string $raw, int $status): string
    {
        $error = $body['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return 'Evolution HTTP '.$status.': '.$error['message'];
        }

        foreach (['error', 'message'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '') {
                return 'Evolution HTTP '.$status.': '.$body[$key];
            }
        }

        $nested = $body['response']['message'] ?? null;
        if (is_array($nested) && isset($nested[0]) && is_string($nested[0])) {
            return 'Evolution HTTP '.$status.': '.$nested[0];
        }

        $snippet = mb_substr(trim($raw), 0, 240);

        return 'Evolution HTTP '.$status.($snippet !== '' ? ': '.$snippet : '');
    }

    private function encodeName(string $instanceName): string
    {
        $name = $this->normalizeInstanceName($instanceName);
        if ($name === '') {
            throw new EvolutionRequestException('Informe o nome da instância Evolution.', 0, false);
        }

        return rawurlencode($name);
    }
}
