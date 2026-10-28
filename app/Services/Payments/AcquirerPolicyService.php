<?php

namespace App\Services\Payments;

use App\Gateways\GatewayRegistry;
use App\Models\SellerAcquirerPolicy;
use App\Models\SellerAcquirerPolicyLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Autorização de adquirência por seller — tem prioridade absoluta sobre
 * preferências de roteamento (produto, oferta, app de API, ordem do seller)
 * e sobre o fallback: uma adquirente fora da política nunca é tentada.
 *
 * Registrado como `scoped`: o cache vale por requisição/job, então um worker
 * de fila enxerga a política nova assim que o admin a altera.
 */
class AcquirerPolicyService
{
    private const WALLET_GATEWAYS = ['cajupay', 'stripe'];

    /** @var array<int, array<string, array{mode: string, gateways: list<string>}>> */
    private array $cache = [];

    private ?bool $tableExists = null;

    /** Apple Pay e Google Pay usam a mesma lista de adquirentes (chave `wallet`). */
    public static function methodKey(string $method): string
    {
        return in_array($method, ['apple_pay', 'google_pay'], true) ? 'wallet' : $method;
    }

    /**
     * @return array<string, array{mode: string, gateways: list<string>}>
     */
    public function policyFor(?int $tenantId): array
    {
        $this->tableExists ??= Schema::hasTable('seller_acquirer_policies');
        if ($tenantId === null || $tenantId < 1 || ! $this->tableExists) {
            return [];
        }
        if (! array_key_exists($tenantId, $this->cache)) {
            $row = SellerAcquirerPolicy::query()->where('tenant_id', $tenantId)->first();
            $this->cache[$tenantId] = $row !== null ? self::normalize($row->methods ?? []) : [];
        }

        return $this->cache[$tenantId];
    }

    /** @return array{mode: string, gateways: list<string>} */
    public function ruleFor(?int $tenantId, string $method): array
    {
        return $this->policyFor($tenantId)[self::methodKey($method)]
            ?? ['mode' => SellerAcquirerPolicy::MODE_INHERIT, 'gateways' => []];
    }

    /**
     * Aplica a política sobre a lista de roteamento já calculada.
     *
     * @param  list<string>  $routing
     * @return list<string>
     */
    public function filter(?int $tenantId, string $method, array $routing, bool $platformDisabled = false): array
    {
        if ($platformDisabled) {
            return [];
        }

        $rule = $this->ruleFor($tenantId, $method);

        if ($rule['mode'] === SellerAcquirerPolicy::MODE_DISABLED) {
            return [];
        }
        if ($rule['mode'] !== SellerAcquirerPolicy::MODE_RESTRICTED) {
            return array_values($routing);
        }

        // Restrito: a preferência de roteamento vale entre as autorizadas; as
        // demais autorizadas entram depois, na ordem definida pelo admin.
        $authorized = $rule['gateways'];
        $ordered = array_values(array_filter($routing, fn ($slug) => in_array($slug, $authorized, true)));
        foreach ($authorized as $slug) {
            if (! in_array($slug, $ordered, true)) {
                $ordered[] = $slug;
            }
        }

        return array_values(array_unique($ordered));
    }

    /** Para os fluxos que escolhem a adquirente fora da ordem central. */
    public function isAllowed(?int $tenantId, string $method, string $slug): bool
    {
        $rule = $this->ruleFor($tenantId, $method);

        return match ($rule['mode']) {
            SellerAcquirerPolicy::MODE_DISABLED => false,
            SellerAcquirerPolicy::MODE_RESTRICTED => in_array($slug, $rule['gateways'], true),
            default => true,
        };
    }

    /**
     * Grava a política do seller (somente admin) e registra o histórico.
     *
     * @param  array<string, mixed>  $methods
     */
    public function save(int $tenantId, array $methods, ?int $actorUserId, ?string $reason = null): SellerAcquirerPolicy
    {
        $clean = self::validate($methods);

        $policy = DB::transaction(function () use ($tenantId, $clean, $actorUserId, $reason) {
            $row = SellerAcquirerPolicy::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $before = $row?->methods;

            if ($row === null) {
                $row = SellerAcquirerPolicy::query()->create([
                    'tenant_id' => $tenantId,
                    'methods' => $clean,
                    'updated_by_user_id' => $actorUserId,
                ]);
            } else {
                $row->forceFill(['methods' => $clean, 'updated_by_user_id' => $actorUserId])->save();
            }

            SellerAcquirerPolicyLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'before' => $before,
                'after' => $clean,
                'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
            ]);

            return $row;
        });

        unset($this->cache[$tenantId]);
        Log::info('AcquirerPolicy: política do seller alterada', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'methods' => $clean,
        ]);

        return $policy;
    }

    /**
     * Adquirentes que o admin pode autorizar para o método (permitidas na
     * plataforma e com suporte real ao método).
     *
     * @return list<string>
     */
    public static function capableGateways(string $method): array
    {
        $method = self::methodKey($method);
        if ($method === 'wallet') {
            return GatewayRegistry::filterSlugsToAllowedAcquirers(self::WALLET_GATEWAYS);
        }

        $out = [];
        foreach (GatewayRegistry::allowedAcquirers() as $g) {
            $slug = (string) ($g['slug'] ?? '');
            if ($slug !== '' && in_array($method, $g['methods'] ?? [], true)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $methods
     * @return array<string, array{mode: string, gateways: list<string>}>
     */
    public static function validate(array $methods): array
    {
        $errors = [];
        $clean = [];

        foreach ($methods as $method => $rule) {
            if (! in_array($method, SellerAcquirerPolicy::METHODS, true)) {
                $errors["methods.{$method}"] = 'Método de pagamento desconhecido.';

                continue;
            }
            $mode = is_array($rule) ? (string) ($rule['mode'] ?? '') : '';
            if (! in_array($mode, [SellerAcquirerPolicy::MODE_INHERIT, SellerAcquirerPolicy::MODE_RESTRICTED, SellerAcquirerPolicy::MODE_DISABLED], true)) {
                $errors["methods.{$method}.mode"] = 'Modo inválido.';

                continue;
            }
            if ($mode === SellerAcquirerPolicy::MODE_INHERIT) {
                continue;
            }
            if ($mode === SellerAcquirerPolicy::MODE_DISABLED) {
                $clean[$method] = ['mode' => $mode, 'gateways' => []];

                continue;
            }

            $capable = self::capableGateways($method);
            $gateways = [];
            foreach ((array) ($rule['gateways'] ?? []) as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '' || in_array($slug, $gateways, true)) {
                    continue;
                }
                if (! in_array($slug, $capable, true)) {
                    $errors["methods.{$method}.gateways"] = "A adquirente {$slug} não atende este método.";

                    continue 2;
                }
                $gateways[] = $slug;
            }
            if ($gateways === []) {
                $errors["methods.{$method}.gateways"] = 'Escolha ao menos uma adquirente ou desative o método.';

                continue;
            }
            $clean[$method] = ['mode' => $mode, 'gateways' => $gateways];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Política salva ignora adquirentes que deixaram de ser permitidas na plataforma.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, array{mode: string, gateways: list<string>}>
     */
    private static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $method => $rule) {
            if (! is_array($rule) || ! in_array($method, SellerAcquirerPolicy::METHODS, true)) {
                continue;
            }
            $mode = (string) ($rule['mode'] ?? SellerAcquirerPolicy::MODE_INHERIT);
            if ($mode === SellerAcquirerPolicy::MODE_DISABLED) {
                $out[$method] = ['mode' => $mode, 'gateways' => []];
            } elseif ($mode === SellerAcquirerPolicy::MODE_RESTRICTED) {
                $capable = self::capableGateways($method);
                $gateways = array_values(array_filter(
                    array_map('strval', (array) ($rule['gateways'] ?? [])),
                    fn (string $s) => in_array($s, $capable, true)
                ));
                $out[$method] = ['mode' => $mode, 'gateways' => $gateways];
            }
        }

        return $out;
    }
}
