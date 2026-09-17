<?php

namespace App\Services\Okto;

use App\Gateways\Okto\OktoDriver;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Log;

class OktoWebhookBootstrapService
{
    public function __construct(
        private readonly OktoDriver $driver = new OktoDriver,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{credentials: array<string, mixed>, warning: ?string}
     */
    public function bootstrap(array $credentials): array
    {
        $url = GatewayWebhookUrl::forGateway('okto');
        if ($url === '' || str_contains($url, 'localhost') || ! str_starts_with($url, 'https://')) {
            return [
                'credentials' => $credentials,
                'warning' => 'Okto: configure GETFY_WEBHOOK_PUBLIC_URL (HTTPS público) para registrar o webhook automaticamente, ou cadastre a URL no BOS.',
            ];
        }

        try {
            $this->driver->registerWebhookUrls($credentials, $url);
        } catch (\Throwable $e) {
            Log::warning('OktoWebhookBootstrap: registro falhou', ['error' => $e->getMessage()]);

            return [
                'credentials' => $credentials,
                'warning' => 'Okto: falha ao registrar webhook ('.$e->getMessage().'). Cadastre a URL no BOS ou tente “Testar conexão” de novo.',
            ];
        }

        return [
            'credentials' => $credentials,
            'warning' => null,
        ];
    }
}
