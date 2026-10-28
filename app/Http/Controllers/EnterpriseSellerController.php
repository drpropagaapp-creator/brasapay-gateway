<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseDomain;
use App\Services\Enterprise\EnterpriseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Enterprise (seller): ver a marca liberada e conectar o domínio próprio do checkout. */
class EnterpriseSellerController extends Controller
{
    public function __construct(private EnterpriseService $enterprise) {}

    public function show(Request $request): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        $account = EnterpriseAccount::query()->where('tenant_id', $tenantId)->first();

        return Inertia::render('Enterprise/Index', [
            'account' => $account ? ['status' => $account->status, 'display_name' => $account->display_name, 'max_domains' => $account->maxDomains()] : null,
            'branding' => array_intersect_key((array) (BrandingSetting::query()->where('tenant_id', $tenantId)->first()?->data ?? []), array_flip(EnterpriseService::BRANDING_KEYS)),
            'domains' => EnterpriseDomain::query()->where('tenant_id', $tenantId)->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->orderBy('id')->get()
                ->map(fn (EnterpriseDomain $d) => [
                    'id' => $d->id, 'host' => $d->host, 'status' => $d->status, 'txt_name' => $d->txtName(), 'txt_value' => $d->txtValue(),
                    'dns_ok' => $d->dns_verified_at !== null, 'https_ok' => $d->https_verified_at !== null, 'last_error' => $d->last_error,
                    'last_checked_at' => $d->last_checked_at?->toIso8601String(),
                ])->values(),
            'app_host' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'can_manage' => ! $request->user()->isTeam() && $account?->isActive(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner($request);
        $v = $request->validate(['host' => ['required', 'string', 'max:200']]);
        $this->enterprise->addDomain((int) $request->user()->tenant_id, $v['host']);

        return back()->with('success', 'Domínio adicionado. Crie o registro TXT e depois clique em verificar.');
    }

    public function verify(Request $request, EnterpriseDomain $domain): RedirectResponse
    {
        $this->owner($request, $domain);
        $d = $this->enterprise->verify($domain);

        return back()->with($d->status === EnterpriseDomain::STATUS_ACTIVE ? 'success' : 'error',
            $d->status === EnterpriseDomain::STATUS_ACTIVE ? 'Domínio ativo.' : ($d->last_error ?: 'Ainda não verificado.'));
    }

    public function destroy(Request $request, EnterpriseDomain $domain): RedirectResponse
    {
        $this->owner($request, $domain);
        $this->enterprise->removeDomain($domain);

        return back()->with('success', 'Domínio removido. O checkout deixa de responder nele.');
    }

    /** Responde o token de verificação só no próprio domínio cadastrado (prova de que o HTTPS chega aqui). */
    public function wellKnown(Request $request, string $token): \Illuminate\Http\Response
    {
        $ok = EnterpriseDomain::query()->where('host', strtolower($request->getHost()))->where('verification_token', $token)
            ->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->exists();
        abort_unless($ok, 404);

        return response($token, 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    private function owner(Request $request, ?EnterpriseDomain $domain = null): void
    {
        $user = $request->user();
        abort_if($user->isTeam(), 403);
        abort_if(EnterpriseAccount::activeFor((int) $user->tenant_id) === null, 403, 'White-label não está ativo para esta conta.');
        if ($domain !== null) {
            abort_unless((int) $domain->tenant_id === (int) $user->tenant_id, 404);
        }
    }
}
