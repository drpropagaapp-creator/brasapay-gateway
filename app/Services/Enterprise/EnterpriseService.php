<?php

namespace App\Services\Enterprise;

use App\Models\BrandingSetting;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseDomain;
use App\Services\PlatformAuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brasa Enterprise — white-label. Muda só a apresentação (marca, menu, domínio do checkout).
 * Tenant financeiro, credenciais de gateway, carteira e responsabilidade regulatória continuam os mesmos.
 */
class EnterpriseService
{
    private const HOST_CACHE = 'enterprise_domain_host:';

    /** Campos de marca que o admin define para a conta (mesmo registro de personalização da plataforma). */
    public const BRANDING_KEYS = ['app_name', 'theme_primary', 'app_logo', 'app_logo_dark', 'favicon_url', 'login_hero_tagline'];

    public function __construct(private DomainProbe $probe) {}

    public function enable(int $tenantId, int $adminId, ?string $displayName): EnterpriseAccount
    {
        $account = EnterpriseAccount::query()->updateOrCreate(['tenant_id' => $tenantId], [
            'status' => EnterpriseAccount::STATUS_ACTIVE, 'display_name' => $displayName, 'suspension_reason' => null, 'enabled_by' => $adminId,
        ]);
        $this->forgetTenantHosts($tenantId);
        PlatformAuditService::log('enterprise.enabled', ['tenant_id' => $tenantId]);

        return $account;
    }

    public function setSuspended(EnterpriseAccount $account, bool $suspended, string $reason): void
    {
        $account->update(['status' => $suspended ? EnterpriseAccount::STATUS_SUSPENDED : EnterpriseAccount::STATUS_ACTIVE, 'suspension_reason' => $suspended ? $reason : null]);
        $this->forgetTenantHosts((int) $account->tenant_id);
        PlatformAuditService::log($suspended ? 'enterprise.suspended' : 'enterprise.reactivated', ['tenant_id' => $account->tenant_id, 'reason' => $reason]);
    }

    /** @param array{display_name?: string|null, max_domains?: int, hidden_nav?: list<string>} $data */
    public function updateSettings(EnterpriseAccount $account, array $data): void
    {
        $before = $account->only(['display_name', 'features', 'limits']);
        $account->update([
            'display_name' => isset($data['display_name']) ? mb_substr(strip_tags((string) $data['display_name']), 0, 120) : $account->display_name,
            'limits' => ['max_domains' => max(0, min(20, (int) ($data['max_domains'] ?? $account->maxDomains())))],
            'features' => ['hidden_nav' => array_values(array_unique(array_filter((array) ($data['hidden_nav'] ?? []), fn ($h) => is_string($h) && preg_match('#^/[a-z0-9/_-]{1,60}$#', $h))))],
        ]);
        PlatformAuditService::log('enterprise.settings', ['tenant_id' => $account->tenant_id, 'before' => $before, 'after' => $account->only(['display_name', 'features', 'limits'])]);
    }

    /** Grava a marca no registro de personalização da conta (o mesmo que o painel já aplica). */
    public function updateBranding(int $tenantId, array $input): void
    {
        $clean = [];
        foreach (self::BRANDING_KEYS as $key) {
            $v = trim((string) ($input[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $clean[$key] = match ($key) {
                'theme_primary' => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : throw ValidationException::withMessages([$key => 'Use uma cor no formato #RRGGBB.']),
                'app_logo', 'app_logo_dark', 'favicon_url' => preg_match('#^https://[^\s<>"]{4,500}$#', $v) ? $v : throw ValidationException::withMessages([$key => 'Use um endereço https:// da imagem.']),
                default => mb_substr(strip_tags($v), 0, 120),
            };
        }

        $row = BrandingSetting::query()->firstOrCreate(['tenant_id' => $tenantId], ['data' => []]);
        $data = is_array($row->data) ? $row->data : [];
        $before = array_intersect_key($data, array_flip(self::BRANDING_KEYS));
        foreach (self::BRANDING_KEYS as $key) {
            unset($data[$key]);
        }
        $row->update(['data' => $data + $clean]);
        PlatformAuditService::log('enterprise.branding', ['tenant_id' => $tenantId, 'before' => $before, 'after' => $clean]);
    }

    public function addDomain(int $tenantId, string $rawHost): EnterpriseDomain
    {
        $account = EnterpriseAccount::activeFor($tenantId);
        abort_if($account === null, 403, 'White-label não está ativo para esta conta.');
        $host = $this->normalizeHost($rawHost);

        return DB::transaction(function () use ($tenantId, $account, $host) {
            $used = EnterpriseDomain::query()->where('tenant_id', $tenantId)->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->lockForUpdate()->count();
            if ($used >= $account->maxDomains()) {
                throw ValidationException::withMessages(['host' => 'Limite de domínios desta conta atingido.']);
            }
            $existing = EnterpriseDomain::query()->where('host', $host)->first();
            if ($existing && ($existing->status !== EnterpriseDomain::STATUS_REMOVED || (int) $existing->tenant_id !== $tenantId)) {
                throw ValidationException::withMessages(['host' => 'Este domínio já está cadastrado.']);
            }
            $token = Str::lower(Str::random(40));
            if ($existing) {
                $existing->update(['status' => EnterpriseDomain::STATUS_PENDING, 'verification_token' => $token, 'dns_verified_at' => null,
                    'https_verified_at' => null, 'consecutive_failures' => 0, 'last_error' => null]);

                return $existing;
            }

            return EnterpriseDomain::query()->create(['tenant_id' => $tenantId, 'host' => $host, 'verification_token' => $token]);
        });
    }

    /** Confere DNS (posse) e HTTPS (certificado e roteamento até a plataforma). Ativa só com os dois. */
    public function verify(EnterpriseDomain $domain): EnterpriseDomain
    {
        abort_if($domain->status === EnterpriseDomain::STATUS_REMOVED, 404);
        $dnsOk = in_array($domain->txtValue(), $this->probe->txtRecords($domain->txtName()), true);
        $httpsOk = $dnsOk && $this->probe->httpsServesToken($domain->host, $domain->verification_token);

        $domain->update([
            'last_checked_at' => now(),
            'dns_verified_at' => $dnsOk ? ($domain->dns_verified_at ?? now()) : null,
            'https_verified_at' => $httpsOk ? now() : null,
            'status' => $dnsOk && $httpsOk ? EnterpriseDomain::STATUS_ACTIVE : EnterpriseDomain::STATUS_PENDING,
            'consecutive_failures' => 0,
            'last_error' => ! $dnsOk ? 'Registro TXT não encontrado.' : (! $httpsOk ? 'O domínio não respondeu por HTTPS até a plataforma.' : null),
        ]);
        Cache::forget(self::HOST_CACHE.$domain->host);
        if ($domain->status === EnterpriseDomain::STATUS_ACTIVE) {
            PlatformAuditService::log('enterprise.domain.activated', ['tenant_id' => $domain->tenant_id, 'host' => $domain->host]);
        }

        return $domain;
    }

    /** Reverificação diária: domínio que perdeu o TXT ou o HTTPS é desativado após falhas seguidas. */
    public function recheck(EnterpriseDomain $domain): void
    {
        $ok = in_array($domain->txtValue(), $this->probe->txtRecords($domain->txtName()), true)
            && $this->probe->httpsServesToken($domain->host, $domain->verification_token);
        $failures = $ok ? 0 : $domain->consecutive_failures + 1;
        $failed = $failures >= EnterpriseDomain::MAX_FAILURES;
        $domain->update([
            'last_checked_at' => now(), 'consecutive_failures' => min(255, $failures),
            'status' => $failed ? EnterpriseDomain::STATUS_FAILED : $domain->status,
            'last_error' => $ok ? null : 'Reverificação falhou ('.$failures.'x).',
        ]);
        if ($failed) {
            Cache::forget(self::HOST_CACHE.$domain->host);
            PlatformAuditService::log('enterprise.domain.failed', ['tenant_id' => $domain->tenant_id, 'host' => $domain->host]);
        }
    }

    public function removeDomain(EnterpriseDomain $domain): void
    {
        $domain->update(['status' => EnterpriseDomain::STATUS_REMOVED]);
        Cache::forget(self::HOST_CACHE.$domain->host);
    }

    /**
     * Situação do host: tenant (> 0) se for domínio white-label ativo de conta ativa; -1 se estiver
     * cadastrado mas não puder servir (pendente, desativado ou conta suspensa); 0 se não for nosso.
     */
    public function hostState(string $host): int
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || $host === $this->appHost()) {
            return 0;
        }

        return (int) Cache::remember(self::HOST_CACHE.$host, 60, function () use ($host) {
            if (! Schema::hasTable('enterprise_domains')) {
                return 0;
            }
            $domain = EnterpriseDomain::query()->where('host', $host)->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->first();
            // Cadastro sem posse comprovada (TXT) nunca muda o roteamento de um host.
            if ($domain === null || $domain->dns_verified_at === null) {
                return 0;
            }
            if ($domain->status === EnterpriseDomain::STATUS_ACTIVE && EnterpriseAccount::activeFor((int) $domain->tenant_id) !== null) {
                return (int) $domain->tenant_id;
            }

            // Posse comprovada há pouco, mas sem poder servir (desativado/suspenso): bloqueia. Prova antiga caduca.
            return $domain->dns_verified_at->gt(now()->subDays(30)) ? -1 : 0;
        });
    }

    public function tenantForHost(string $host): ?int
    {
        $state = $this->hostState($host);

        return $state > 0 ? $state : null;
    }

    private function normalizeHost(string $raw): string
    {
        $host = strtolower(trim($raw));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', (string) $host)[0], '.');
        $host = explode(':', $host)[0];
        $app = $this->appHost();

        $invalid = ! preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            || filter_var($host, FILTER_VALIDATE_IP)
            || in_array($host, ['localhost'], true)
            || $this->isPlatformHost($host);
        if ($invalid) {
            throw ValidationException::withMessages(['host' => 'Informe um domínio válido seu (ex.: pagamento.suaempresa.com.br).']);
        }
        if (Schema::hasTable('member_area_domains') && DB::table('member_area_domains')->whereRaw('LOWER(value) = ?', [$host])->exists()) {
            throw ValidationException::withMessages(['host' => 'Este domínio já é usado por uma área de membros.']);
        }

        return $host;
    }

    /**
     * Hosts da própria plataforma: o host do APP_URL, o domínio dele (ex.: app.brasapay.com.br →
     * brasapay.com.br) e qualquer subdomínio desse domínio, mais os listados em ENTERPRISE_RESERVED_HOSTS.
     */
    private function isPlatformHost(string $host): bool
    {
        $roots = array_filter(array_map('trim', explode(',', strtolower((string) config('getfy.enterprise_reserved_hosts', '')))));
        $labels = explode('.', $this->appHost());
        if (count($labels) >= 2) {
            // Domínio registrável aproximado: últimos 2 rótulos, ou 3 em sufixos como .com.br.
            $take = count($labels) >= 3 && strlen($labels[count($labels) - 2]) <= 3 ? 3 : 2;
            $roots[] = implode('.', array_slice($labels, -$take));
        }
        foreach (array_unique(array_filter($roots)) as $root) {
            if ($host === $root || str_ends_with($host, '.'.$root)) {
                return true;
            }
        }

        return false;
    }

    /** Cadastro pendente sem verificação por mais de 7 dias é descartado. */
    public function expireStalePending(): int
    {
        return EnterpriseDomain::query()->where('status', EnterpriseDomain::STATUS_PENDING)->whereNull('dns_verified_at')
            ->where('created_at', '<', now()->subDays(7))->update(['status' => EnterpriseDomain::STATUS_REMOVED, 'updated_at' => now()]);
    }

    private function appHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    private function forgetTenantHosts(int $tenantId): void
    {
        foreach (EnterpriseDomain::query()->where('tenant_id', $tenantId)->pluck('host') as $host) {
            Cache::forget(self::HOST_CACHE.$host);
        }
    }
}
