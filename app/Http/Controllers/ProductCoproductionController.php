<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Mail\CoproductionInvitationMail;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\Coproduction\RevenueSplitCalculator;
use App\Services\EffectiveMerchantFees;
use App\Services\EffectiveSettlementRules;
use App\Services\PlatformTransactionalMailService;
use App\Services\SellerActivityLogService;
use App\Services\TeamAccessService;
use App\Support\MoneyDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductCoproductionController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        protected PlatformTransactionalMailService $mailService
    ) {}

    public function store(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'commission_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'commission_on_direct_sales' => ['boolean'],
            'commission_on_affiliate_sales' => ['boolean'],
            'duration_preset' => ['required', 'string', Rule::in(array_merge([ProductCoproducer::DURATION_ETERNAL], ProductCoproducer::DURATION_DAYS))],
        ]);

        ProductCoproducer::expireOverdue();

        $email = ProductCoproducer::normalizeEmail($validated['email']);
        $owner = auth()->user()->kycSubjectUser();
        $ownerEmail = ProductCoproducer::normalizeEmail((string) $owner->email);

        if ($email === $ownerEmail) {
            return back()->withErrors(['email' => 'Informe o e-mail de outro infoprodutor, não o seu.']);
        }

        $inviterUser = auth()->user();
        $inviterId = (int) $inviterUser->id;

        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingUser !== null && ! $existingUser->isInfoprodutor()) {
            return back()->withErrors(['email' => 'Este e-mail pertence a um usuário que não é infoprodutor.']);
        }

        if (ProductCoproducer::query()
            ->where('product_id', $produto->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('status', [ProductCoproducer::STATUS_PENDING, ProductCoproducer::STATUS_ACTIVE])
            ->exists()) {
            return back()->withErrors(['email' => 'Já existe convite ou co-produção ativa para este e-mail.']);
        }

        $sumPct = (float) ProductCoproducer::query()
            ->where('product_id', $produto->id)
            ->whereIn('status', [ProductCoproducer::STATUS_PENDING, ProductCoproducer::STATUS_ACTIVE])
            ->sum('commission_percent');

        if (round($sumPct + (float) $validated['commission_percent'], 2) > 100.01) {
            return back()->withErrors(['commission_percent' => 'A soma das comissões dos co-produtores não pode exceder 100%.']);
        }

        if ($produto->affiliate_enabled && (float) $produto->affiliate_commission_percent > 0 && $request->boolean('commission_on_affiliate_sales', false)) {
            $sumCoproAffiliate = (float) ProductCoproducer::query()
                ->where('product_id', $produto->id)
                ->whereIn('status', [ProductCoproducer::STATUS_PENDING, ProductCoproducer::STATUS_ACTIVE])
                ->where('commission_on_affiliate_sales', true)
                ->sum('commission_percent');
            if (round($sumCoproAffiliate + (float) $validated['commission_percent'] + (float) $produto->affiliate_commission_percent, 2) > 100.01) {
                return back()->withErrors(['commission_percent' => 'A soma das comissões em vendas por afiliado (co-produtores + afiliado) não pode exceder 100%.']);
            }
        }

        $token = Str::random(48);

        $invitation = ProductCoproducer::query()->create([
            'product_id' => $produto->id,
            'inviter_user_id' => $inviterId,
            'co_producer_user_id' => null,
            'email' => $email,
            'status' => ProductCoproducer::STATUS_PENDING,
            'token' => $token,
            'commission_percent' => $validated['commission_percent'],
            'commission_on_direct_sales' => $request->boolean('commission_on_direct_sales', true),
            'commission_on_affiliate_sales' => $request->boolean('commission_on_affiliate_sales', false),
            'duration_preset' => $validated['duration_preset'],
        ]);

        $branding = [
            'app_name' => config('getfy.app_name', 'Getfy'),
            'theme_primary' => config('getfy.theme_primary', '#0050fc'),
        ];

        $acceptUrl = url('/coproducao/convite/'.$token);
        $registerUrl = url('/cadastro?coproducer_invite='.$token);

        $this->mailService->send(
            new CoproductionInvitationMail(
                $invitation,
                $produto,
                $inviterUser,
                $branding,
                $acceptUrl,
                $registerUrl
            ),
            $email
        );

        $this->logSellerActivity(SellerActivityLogService::COPRODUCTION_INVITED, $invitation, [
            'email' => $email,
            'product_id' => $produto->id,
            'product_name' => $produto->name,
        ]);

        return back()->with('success', 'Convite enviado para '.$email.'.');
    }

    public function destroy(Product $produto, ProductCoproducer $coproducer): RedirectResponse
    {
        $this->authorizeProduct($produto);

        if ($coproducer->product_id !== $produto->id) {
            abort(404);
        }

        if (in_array($coproducer->status, [
            ProductCoproducer::STATUS_REVOKED,
            ProductCoproducer::STATUS_DECLINED,
            ProductCoproducer::STATUS_EXPIRED,
        ], true)) {
            return back()->with('success', 'Co-produção já estava encerrada.');
        }

        $coproducer->update(['status' => ProductCoproducer::STATUS_REVOKED]);

        $this->logSellerActivity(SellerActivityLogService::COPRODUCTION_REMOVED, $coproducer, [
            'email' => $coproducer->email,
            'product_id' => $produto->id,
            'product_name' => $produto->name,
        ]);

        return back()->with('success', 'Co-produção revogada.');
    }

    /**
     * Simulação da divisão de uma venda direta com a mesma regra, taxas e prazos
     * que valem ao creditar a carteira. Convites pendentes entram como
     * estimativa (taxa do seller), pois a conta do co-produtor ainda não existe.
     */
    public function simulate(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['nullable', 'string', Rule::in(['pix', 'card', 'boleto'])],
        ]);
        $method = $validated['method'] ?? 'pix';
        $sellerTenantId = (int) $produto->tenant_id;
        $grossCents = MoneyDecimal::toCents($validated['amount']);

        ProductCoproducer::expireOverdue();
        $rows = ProductCoproducer::query()
            ->with('coProducer:id,tenant_id,name')
            ->where('product_id', $produto->id)
            ->whereIn('status', [ProductCoproducer::STATUS_ACTIVE, ProductCoproducer::STATUS_PENDING])
            ->where('commission_on_direct_sales', true)
            ->orderBy('id')
            ->get();

        $coproducers = [];
        $labels = [];
        foreach ($rows as $row) {
            $pending = $row->status !== ProductCoproducer::STATUS_ACTIVE || ! $row->co_producer_user_id;
            // Pendente ainda não tem carteira: um id fictício único só separa a fatia na simulação.
            $tenant = $pending ? PHP_INT_MAX - (int) $row->id : ProductCoproducer::walletTenantIdFor($row);
            $coproducers[] = [
                'tenant_id' => $tenant,
                'basis_points' => MoneyDecimal::percentToBasisPoints($row->commission_percent),
                'product_coproducer_id' => (int) $row->id,
            ];
            $labels[(int) $row->id] = [
                'name' => $row->coProducer?->name ?: $row->email,
                'pending' => $pending,
                'fee_tenant_id' => $pending ? $sellerTenantId : $tenant,
            ];
        }

        $split = RevenueSplitCalculator::split($sellerTenantId, [
            ['product_id' => (string) $produto->id, 'cents' => $grossCents, 'coproducers' => $coproducers],
        ]);

        $participants = [];
        foreach ($split['slices'] as $slice) {
            $isSeller = $slice['role'] === 'seller';
            $copId = $isSeller ? null : (int) ($slice['product_coproducer_id'] ?? 0);
            $feeTenant = $isSeller ? $sellerTenantId : ($labels[$copId]['fee_tenant_id'] ?? $sellerTenantId);
            $gross = MoneyDecimal::fromCents((int) $slice['gross_cents']);
            $fee = $gross > 0 ? EffectiveMerchantFees::calculateSaleFee($feeTenant, $method, $gross) : ['fee' => 0.0, 'net' => 0.0];
            $rules = EffectiveSettlementRules::forTenantMethod($feeTenant, $method);

            $participants[] = [
                'role' => $slice['role'],
                'name' => $isSeller ? 'Você (produtor)' : ($labels[$copId]['name'] ?? 'Co-produtor'),
                'pending_invite' => $isSeller ? false : (bool) ($labels[$copId]['pending'] ?? false),
                'percent' => round((int) $slice['gross_cents'] * 100 / max(1, $grossCents), 2),
                'gross' => $gross,
                'fee' => round((float) $fee['fee'], 2),
                'net' => round((float) $fee['net'], 2),
                'reserve_percent' => (float) ($rules['reserve_percent'] ?? 0),
                'days_to_available' => (int) ($rules['days_to_available'] ?? 0),
            ];
        }

        return response()->json([
            'amount' => MoneyDecimal::fromCents($grossCents),
            'method' => $method,
            'participants' => $participants,
            'issues' => $split['issues'],
        ]);
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
