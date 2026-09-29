<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\SellerActivityLog;
use App\Models\User;
use App\Services\SellerActivityLogService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerProfileRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function seller(array $extra = []): User
    {
        $attrs = array_merge([
            'name' => 'Maria Silva',
            'email' => 'maria.perfil@example.com',
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'password' => Hash::make('password'),
            'phone' => '11988776655',
            'person_type' => 'pf',
            'document' => '52998224725',
            'birth_date' => '1990-05-12',
            'address_zip' => '01310100',
            'address_street' => 'Avenida Paulista',
            'address_number' => '1000',
            'address_complement' => 'Sala 12',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'kyc_reviewed_at' => '2026-04-01 14:30:00',
        ], $extra);

        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = $attrs['kyc_status'] ?? User::KYC_APPROVED;
        }

        $seller = User::factory()->create($attrs);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_profile_page_shows_registration_data(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Index')
                ->where('user.email', 'maria.perfil@example.com')
                ->where('registration.name', 'Maria Silva')
                ->where('registration.email', 'maria.perfil@example.com')
                ->where('registration.whatsapp', '(11) 98877-6655')
                ->where('registration.document', '529.982.247-25')
                ->where('registration.birth_date', '12/05/1990')
                ->where('registration.address_line', 'Avenida Paulista, 1000 — Sala 12 — Bela Vista — São Paulo — SP — 01310-100')
                ->where('registration.kyc_reviewed_at', fn ($value) => is_string($value) && $value !== '')
                ->where('registration.created_at', fn ($value) => is_string($value) && $value !== '')
            );
    }

    public function test_profile_update_does_not_change_email(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->post(route('profile.update'), [
                'name' => 'Maria Oliveira',
                'email' => 'novo.email@example.com',
                'username' => 'mariaoliveira',
            ])
            ->assertRedirect(route('profile.index'));

        $seller->refresh();
        $this->assertSame('maria.perfil@example.com', $seller->email);
        $this->assertSame('Maria Oliveira', $seller->name);
        $this->assertSame('mariaoliveira', $seller->username);
    }

    public function test_profile_can_save_trade_name(): void
    {
        if (! Schema::hasColumn('users', 'trade_name')) {
            $this->markTestSkipped('trade_name column');
        }

        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->post(route('profile.update'), [
                'name' => 'Maria Silva',
                'trade_name' => '  Academia Digital  ',
                'username' => 'mariasilva',
            ])
            ->assertRedirect(route('profile.index'));

        $seller->refresh();
        $this->assertSame('Academia Digital', $seller->trade_name);

        $this->actingAs($seller)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Index')
                ->where('user.trade_name', 'Academia Digital')
            );
    }

    public function test_profile_can_update_whatsapp_and_logs_change(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            $this->markTestSkipped('phone column');
        }

        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->put(route('profile.update-whatsapp'), [
                'phone' => '21987654321',
                'name' => 'Tentativa de mudar nome',
                'email' => 'outro.email@example.com',
                'document' => '39053344705',
            ])
            ->assertRedirect(route('profile.index'));

        $seller->refresh();
        $this->assertSame('5521987654321', $seller->phone);
        $this->assertSame('Maria Silva', $seller->name);
        $this->assertSame('maria.perfil@example.com', $seller->email);
        $this->assertSame('52998224725', $seller->document);

        if (Schema::hasTable('seller_activity_logs')) {
            $this->assertDatabaseHas('seller_activity_logs', [
                'tenant_id' => $seller->id,
                'actor_user_id' => $seller->id,
                'action' => SellerActivityLogService::PROFILE_WHATSAPP_UPDATED,
            ]);
            $log = SellerActivityLog::query()
                ->where('action', SellerActivityLogService::PROFILE_WHATSAPP_UPDATED)
                ->first();
            $this->assertNotNull($log);
            $this->assertSame(['phone'], $log->metadata['changed'] ?? null);
            $this->assertNotSame('11988776655', $log->metadata['phone_from'] ?? null);
            $this->assertNotSame('5521987654321', $log->metadata['phone_to'] ?? null);
            $this->assertStringContainsString('4321', (string) ($log->metadata['phone_to'] ?? ''));
        }

        $this->actingAs($seller)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Index')
                ->where('registration.whatsapp', '(21) 98765-4321')
            );
    }

    public function test_profile_rejects_invalid_whatsapp(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            $this->markTestSkipped('phone column');
        }

        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->put(route('profile.update-whatsapp'), [
                'phone' => '123',
            ])
            ->assertRedirect(route('profile.index'))
            ->assertSessionHasErrors('phone');

        $seller->refresh();
        $this->assertSame('11988776655', $seller->phone);
    }

    public function test_profile_does_not_log_unchanged_whatsapp(): void
    {
        if (! Schema::hasColumn('users', 'phone') || ! Schema::hasTable('seller_activity_logs')) {
            $this->markTestSkipped('phone or seller_activity_logs');
        }

        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->put(route('profile.update-whatsapp'), [
                'phone' => '(11) 98877-6655',
            ])
            ->assertRedirect(route('profile.index'));

        $this->assertSame(0, SellerActivityLog::query()
            ->where('action', SellerActivityLogService::PROFILE_WHATSAPP_UPDATED)
            ->count());
    }
}
