<?php

namespace App\Services\PlatformWhatsapp;

use App\Models\User;
use App\Support\PlatformCompanySettings;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiMessageBuilder;

class PlatformWhatsappMessageVars
{
    public function __construct(
        private UazapiClient $client,
        private UazapiMessageBuilder $builder,
    ) {}

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function forSeller(User $seller, array $extra = []): array
    {
        $name = trim((string) ($seller->name ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : '';
        $reason = trim((string) ($extra['motivo'] ?? ''));

        $vars = [
            'nome' => $name,
            'primeiro_nome' => $first !== '' ? $first : $name,
            'email' => (string) ($seller->email ?? ''),
            'status' => (string) ($seller->account_status ?? ''),
            'motivo' => $reason,
            'motivo_linha' => $reason !== '' ? ' Motivo: '.$reason : '',
            'painel' => url('/dashboard'),
            'kyc_url' => url('/financeiro?tab=seus-dados'),
            'plataforma' => PlatformCompanySettings::platformName(),
        ];

        foreach ($extra as $key => $value) {
            if (is_string($value)) {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function render(string $template, array $vars): string
    {
        return $this->builder->render($template, $vars);
    }

    public function phoneOf(User $seller): ?string
    {
        return $this->client->normalizePhone((string) ($seller->phone ?? ''));
    }
}
