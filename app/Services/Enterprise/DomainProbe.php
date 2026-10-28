<?php

namespace App\Services\Enterprise;

use Illuminate\Support\Facades\Http;

/** Consultas externas da verificação de domínio (isoladas para teste). */
class DomainProbe
{
    /** @return list<string> */
    public function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($r) => is_array($r) ? (string) ($r['txt'] ?? '') : '', $records)));
    }

    /** O domínio responde por HTTPS com certificado válido e chega até esta plataforma? */
    public function httpsServesToken(string $host, string $token): bool
    {
        try {
            // Mesmo filtro dos webhooks: o domínio não pode apontar para rede interna (SSRF).
            \App\Support\WebhookUrlValidator::assertAllowed('https://'.$host.'/');
            $res = Http::timeout(8)->withOptions(['allow_redirects' => false])
                ->get('https://'.$host.'/.well-known/brasapay-domain/'.$token);

            return $res->successful() && trim($res->body()) === $token;
        } catch (\Throwable) {
            return false;
        }
    }
}
