<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseDomain;
use App\Models\User;
use App\Services\Enterprise\EnterpriseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Enterprise (admin): ativar, suspender, limitar, configurar marca e menu de contas white-label. */
class EnterpriseController extends Controller
{
    public function __construct(private EnterpriseService $enterprise) {}

    public function index(): Response
    {
        $accounts = EnterpriseAccount::query()->latest('id')->limit(200)->get();
        $users = User::query()->whereIn('id', $accounts->pluck('tenant_id'))->get(['id', 'name', 'email'])->keyBy('id');
        $branding = BrandingSetting::query()->whereIn('tenant_id', $accounts->pluck('tenant_id'))->get()->keyBy('tenant_id');
        $domains = EnterpriseDomain::query()->whereIn('tenant_id', $accounts->pluck('tenant_id'))->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->get()->groupBy('tenant_id');

        return Inertia::render('Platform/Enterprise/Index', [
            'branding_keys' => EnterpriseService::BRANDING_KEYS,
            'accounts' => $accounts->map(fn (EnterpriseAccount $a) => [
                'id' => $a->id, 'tenant_id' => $a->tenant_id, 'status' => $a->status, 'display_name' => $a->display_name,
                'seller' => ($u = $users->get($a->tenant_id)) ? ['name' => $u->name, 'email' => $u->email] : null,
                'max_domains' => $a->maxDomains(), 'hidden_nav' => $a->hiddenNav(), 'suspension_reason' => $a->suspension_reason,
                'branding' => array_intersect_key((array) ($branding->get($a->tenant_id)?->data ?? []), array_flip(EnterpriseService::BRANDING_KEYS)),
                'domains' => ($domains->get($a->tenant_id) ?? collect())->map(fn ($d) => ['id' => $d->id, 'host' => $d->host, 'status' => $d->status, 'last_error' => $d->last_error])->values(),
            ])->values(),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $v = $request->validate(['seller' => ['required', 'string', 'max:190'], 'display_name' => ['nullable', 'string', 'max:120']]);
        $seller = User::query()->where('role', User::ROLE_INFOPRODUTOR)
            ->where(fn ($q) => ctype_digit($v['seller']) ? $q->whereKey((int) $v['seller']) : $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($v['seller']))]))
            ->first();
        if ($seller === null || (int) $seller->tenant_id < 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['seller' => 'Seller não encontrado.']);
        }
        $this->enterprise->enable((int) $seller->tenant_id, $request->user()->id, $v['display_name'] ?? null);

        return back()->with('success', 'White-label ativado.');
    }

    public function update(Request $request, EnterpriseAccount $account): RedirectResponse
    {
        $v = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'max_domains' => ['required', 'integer', 'min:0', 'max:20'],
            'hidden_nav' => ['nullable', 'array', 'max:40'],
            'hidden_nav.*' => ['string', 'max:60'],
            'branding' => ['nullable', 'array'],
        ]);
        $this->enterprise->updateSettings($account, $v);
        $this->enterprise->updateBranding((int) $account->tenant_id, (array) ($v['branding'] ?? []));

        return back()->with('success', 'Conta atualizada.');
    }

    public function status(Request $request, EnterpriseAccount $account): RedirectResponse
    {
        $v = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->enterprise->setSuspended($account, (bool) $v['suspended'], $v['reason']);

        return back()->with('success', $v['suspended'] ? 'White-label suspenso: domínios param de responder.' : 'White-label reativado.');
    }

    public function removeDomain(EnterpriseDomain $domain): RedirectResponse
    {
        $this->enterprise->removeDomain($domain);
        \App\Services\PlatformAuditService::log('enterprise.domain.removed_by_admin', ['tenant_id' => $domain->tenant_id, 'host' => $domain->host]);

        return back()->with('success', 'Domínio removido.');
    }
}
