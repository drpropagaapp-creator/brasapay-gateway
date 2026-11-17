<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Setting;
use App\Models\User;
use App\Services\MerchantOperationalGuard;
use App\Support\KycEnforcement;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformKycEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_default_still_requires_kyc_before_selling(): void
    {
        $seller = $this->seller('pending', User::KYC_NOT_SUBMITTED);

        $this->assertSame(KycEnforcement::ONBOARDING, KycEnforcement::mode());
        $this->assertFalse($seller->isMerchantOperationallyApproved());
        $this->assertSame(
            'Envie seus documentos de verificação de identidade (KYC) para acessar o painel do infoprodutor.',
            $seller->sellerPanelRestrictedMessage()
        );
    }

    public function test_withdrawal_mode_sells_after_basic_approval_and_still_blocks_payout(): void
    {
        Setting::set(KycEnforcement::KEY, KycEnforcement::WITHDRAWAL, null);
        $seller = $this->seller('pending', User::KYC_NOT_SUBMITTED);

        $this->assertFalse($seller->isMerchantOperationallyApproved());
        $this->assertSame(
            'Sua conta aguarda aprovação da plataforma para começar a vender.',
            $seller->sellerPanelRestrictedMessage()
        );

        try {
            MerchantOperationalGuard::assertCanAcceptPayments((int) $seller->tenant_id);
            $this->fail('Pagamento deveria estar bloqueado antes da aprovação básica.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Conta do infoprodutor aguarda aprovação da plataforma.', $e->getMessage());
        }

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/usuarios/aguardando-aprovacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/PendingApproval')
                ->where('kyc_enforcement', KycEnforcement::WITHDRAWAL)
                ->has('users.data', 1)
                ->where('users.data.0.email', $seller->email)
            );

        $this->actingAs($admin)
            ->post('/plataforma/usuarios/'.$seller->id.'/aprovacao-basica')
            ->assertRedirect();

        $seller->refresh();
        $this->assertSame('approved', $seller->account_status);
        $this->assertSame(User::KYC_NOT_SUBMITTED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());

        MerchantOperationalGuard::assertCanAcceptPayments((int) $seller->tenant_id);

        try {
            MerchantOperationalGuard::assertCanRequestWithdrawal($seller);
            $this->fail('Saque deveria continuar exigindo KYC.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Conclua a verificação de identidade (KYC) para solicitar saques.',
                $e->errors()['amount'][0] ?? null
            );
        }

        $seller->forceFill([
            'kyc_status' => User::KYC_APPROVED,
            'kyc_requirements_version' => 2,
        ])->save();
        MerchantOperationalGuard::assertCanRequestWithdrawal($seller->fresh());

        Setting::set(KycEnforcement::KEY, KycEnforcement::ONBOARDING, null);
        $withoutKyc = $this->seller('approved', User::KYC_NOT_SUBMITTED);
        $this->assertFalse($withoutKyc->isMerchantOperationallyApproved());
    }

    public function test_settings_persist_the_enforcement_mode(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'kyc_enforcement' => KycEnforcement::WITHDRAWAL,
            ])
            ->assertRedirect();

        $this->assertSame(KycEnforcement::WITHDRAWAL, KycEnforcement::mode());
    }

    public function test_queue_lists_only_pending_accounts(): void
    {
        Setting::set(KycEnforcement::KEY, KycEnforcement::WITHDRAWAL, null);
        $pending = $this->seller('pending', User::KYC_NOT_SUBMITTED);
        $this->seller('approved', User::KYC_NOT_SUBMITTED);
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/usuarios/aguardando-aprovacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $pending->id)
            );
    }

    private function seller(string $accountStatus, string $kycStatus): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => $accountStatus,
            'kyc_status' => $kycStatus,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }
}
