<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Conta local do vendedor (não apaga outros usuários).
 *
 * Login: /login
 * E-mail: jamilabrao@hotmail.com
 * Senha: 12345678
 */
class LocalDevAccessSeeder extends Seeder
{
    public const EMAIL = 'jamilabrao@hotmail.com';

    public const PASSWORD = '12345678';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Jamil Abraão',
                'password' => self::PASSWORD,
                'role' => User::ROLE_INFOPRODUTOR,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => '11999999999',
                'document' => '52998224725',
                'email_verified_at' => now(),
            ]
        );

        $fill = ['tenant_id' => $user->tenant_id ?: $user->id];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $fill['kyc_status'] = User::KYC_APPROVED;
        }
        if (Schema::hasColumn('users', 'kyc_reviewed_at')) {
            $fill['kyc_reviewed_at'] = now()->subDays(10);
        }
        if (Schema::hasColumn('users', 'seller_onboarded_at')) {
            $fill['seller_onboarded_at'] = $user->seller_onboarded_at ?? now()->subDays(10);
        }
        if (Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
            $fill['privacy_policy_accepted_at'] = $user->privacy_policy_accepted_at ?? now()->subDays(10);
        }
        if (Schema::hasColumn('users', 'terms_accepted_at')) {
            $fill['terms_accepted_at'] = $user->terms_accepted_at ?? now()->subDays(10);
        }
        $user->forceFill($fill)->save();

        $this->command?->info('Conta local pronta (nada foi apagado).');
        $this->command?->table(
            ['Item', 'Valor'],
            [
                ['Painel', url('/login')],
                ['E-mail', self::EMAIL],
                ['Senha', self::PASSWORD],
                ['Perfil', 'infoprodutor (Alunos, produtos, dossiê)'],
            ]
        );
    }
}
