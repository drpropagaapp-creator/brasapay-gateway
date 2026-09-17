<?php

namespace Database\Seeders;

use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dados fictícios para visualizar o dossiê do aluno (Alunos → clicar no produto).
 * Não apaga admin, vendedores nem o restante da instalação.
 *
 * Uso: php artisan db:seed --class=LocalStudentDossierSeeder --force
 */
class LocalStudentDossierSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const STUDENT_EMAIL = 'ana.dossie.demo@example.com';

    private const CHECKOUT_SLUG = 'dossiedemo01';

    private const GATEWAY_ID = 'dossie_demo_seed_v1';

    public function run(): void
    {
        if (! Schema::hasTable('member_student_activity_logs')) {
            $this->command?->error('Rode as migrations antes (tabela member_student_activity_logs).');

            return;
        }

        $seller = $this->resolveSeller();
        if (! $seller) {
            $this->command?->error('Nenhum vendedor/admin encontrado. Faça login na instalação e tente de novo.');

            return;
        }

        $product = $this->upsertProduct($seller);
        $lessons = $this->ensureCurriculum($product);
        $student = $this->upsertStudent($product);
        $this->upsertOrder($seller, $student, $product);
        $this->seedProgressAndLogs($student, $product, $lessons);

        $this->command?->info('Dossiê demo criado sem apagar dados existentes.');
        $this->command?->table(
            ['Item', 'Valor'],
            [
                ['Ver como', 'Painel do vendedor → Alunos → Ana Souza Demo → clique no produto'],
                ['Vendedor (tenant)', $seller->email.' (id '.$seller->id.')'],
                ['Aluno demo', self::STUDENT_EMAIL.' / '.self::PASSWORD],
                ['Produto', $product->name],
                ['Área de membros', url('/m/'.$product->checkout_slug)],
            ]
        );
    }

    private function resolveSeller(): ?User
    {
        $preferred = User::query()
            ->where('email', LocalDevAccessSeeder::EMAIL)
            ->first();
        if ($preferred) {
            if ($preferred->tenant_id === null) {
                $preferred->forceFill(['tenant_id' => $preferred->id])->save();
            }

            return $preferred->fresh();
        }

        $seller = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->first();

        if ($seller) {
            return $seller;
        }

        $seller = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_INFOPRODUTOR, User::ROLE_PLATFORM_ADMIN])
            ->orderBy('id')
            ->first();

        if (! $seller) {
            return null;
        }

        if ($seller->tenant_id === null) {
            $seller->forceFill(['tenant_id' => $seller->id])->save();
        }

        return $seller->fresh();
    }

    private function upsertProduct(User $seller): Product
    {
        $tenantId = $seller->tenant_id ?: $seller->id;
        $existing = Product::query()
            ->where('checkout_slug', self::CHECKOUT_SLUG)
            ->first();

        $config = array_replace_recursive(Product::defaultMemberAreaConfig(), [
            'hero' => [
                'title' => 'Mentoria Demo Dossiê',
                'subtitle' => 'Produto fictício para visualizar acessos, aulas e downloads',
                'overlay' => true,
            ],
            'login' => [
                'title' => 'Mentoria Demo',
                'subtitle' => 'Entre para ver as aulas de demonstração',
            ],
        ]);

        $payload = [
            'tenant_id' => $tenantId,
            'name' => 'Mentoria Demo — Dossiê do aluno',
            'slug' => 'mentoria-demo-dossie-aluno',
            'checkout_slug' => self::CHECKOUT_SLUG,
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 197.00,
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício para testar o dossiê de atividade do aluno.',
            'member_area_config' => $config,
        ];

        if (Schema::hasColumn('products', 'admin_blocked')) {
            $payload['admin_blocked'] = false;
        }
        if (Schema::hasColumn('products', 'approval_status')) {
            $payload['approval_status'] = Product::APPROVAL_APPROVED;
        }

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    /**
     * @return list<MemberLesson>
     */
    private function ensureCurriculum(Product $product): array
    {
        $existing = MemberLesson::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Módulo de boas-vindas',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Comece por aqui',
            'position' => 1,
            'show_title_on_cover' => true,
        ]);

        $lessons = [];
        $lessons[] = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1 — Boas-vindas',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Aula fictícia de boas-vindas para o dossiê.</p>',
            'is_free' => true,
        ]);
        $lessons[] = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 2 — Material complementar',
            'position' => 2,
            'type' => MemberLesson::TYPE_PDF,
            'content_files' => [
                ['url' => 'https://example.com/demo-apostila.pdf', 'name' => 'Apostila Demo.pdf'],
                ['url' => 'https://example.com/demo-planilha.xlsx', 'name' => 'Planilha de exercícios.xlsx'],
            ],
            'content_url' => 'https://example.com/demo-apostila.pdf',
        ]);
        $lessons[] = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 3 — Encerramento',
            'position' => 3,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Última aula da demo do dossiê.</p>',
        ]);

        return $lessons;
    }

    private function upsertStudent(Product $product): User
    {
        $student = User::query()->updateOrCreate(
            ['email' => self::STUDENT_EMAIL],
            [
                'name' => 'Ana Souza Demo',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_CLIENTE,
                'account_status' => 'approved',
                'tenant_id' => null,
                'phone' => '11998887766',
                'document' => '52998224725',
                'email_verified_at' => now(),
            ]
        );

        if (! $product->users()->where('users.id', $student->id)->exists()) {
            $product->users()->attach($student->id, [
                'created_at' => now()->subDays(12),
                'updated_at' => now()->subDays(12),
            ]);
        } else {
            $product->users()->updateExistingPivot($student->id, [
                'created_at' => now()->subDays(12),
                'updated_at' => now()->subDays(12),
            ]);
        }

        return $student->fresh();
    }

    private function upsertOrder(User $seller, User $student, Product $product): void
    {
        $paidAt = Carbon::now()->subDays(12)->setTime(14, 22, 11);
        $payload = [
            'tenant_id' => $seller->tenant_id ?: $seller->id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 197.00,
            'email' => $student->email,
            'cpf' => $student->document,
            'phone' => $student->phone,
            'gateway' => 'demo_seed',
            'gateway_id' => self::GATEWAY_ID,
            'payment_method' => 'pix',
            'approved_manually' => false,
            'metadata' => ['seed' => 'local_student_dossier'],
        ];

        $order = Order::query()->where('gateway_id', self::GATEWAY_ID)->first();
        if ($order) {
            $order->forceFill($payload)->save();
        } else {
            $order = Order::query()->create($payload);
        }

        $timestamps = ['created_at' => $paidAt, 'updated_at' => $paidAt];
        if (Schema::hasColumn('orders', 'paid_at')) {
            $timestamps['paid_at'] = $paidAt;
        }
        $order->forceFill($timestamps)->save();

        if (Schema::hasTable('order_items') && ! $order->orderItems()->exists()) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => 197.00,
                'position' => 0,
            ]);
        }
    }

    /**
     * @param  list<MemberLesson>  $lessons
     */
    private function seedProgressAndLogs(User $student, Product $product, array $lessons): void
    {
        MemberStudentActivityLog::query()
            ->where('user_id', $student->id)
            ->where('product_id', (string) $product->id)
            ->delete();

        MemberLessonProgress::query()
            ->where('user_id', $student->id)
            ->where('product_id', $product->id)
            ->delete();

        $welcome = $lessons[0] ?? null;
        $material = $lessons[1] ?? null;
        $closing = $lessons[2] ?? null;

        $base = Carbon::now()->subDays(2)->setTime(19, 4, 0);

        $this->log($student, $product, MemberStudentActivityLog::EVENT_LOGIN, $base->copy(), '187.12.44.91', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $this->log($student, $product, MemberStudentActivityLog::EVENT_VISIT, $base->copy()->addMinutes(1), '187.12.44.91');

        if ($welcome) {
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LESSON_VIEWED, $base->copy()->addMinutes(3), '187.12.44.91', null, $welcome, $welcome->title);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LESSON_COMPLETED, $base->copy()->addMinutes(8), '187.12.44.91', null, $welcome, $welcome->title);
            MemberLessonProgress::query()->create([
                'user_id' => $student->id,
                'member_lesson_id' => $welcome->id,
                'product_id' => $product->id,
                'completed_at' => $base->copy()->addMinutes(8),
                'progress_percent' => 100,
            ]);
        }

        if ($material) {
            $opened = $base->copy()->addHours(22)->setTime(10, 15, 40);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LOGIN, $opened->copy(), '201.17.88.12', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)');
            $this->log($student, $product, MemberStudentActivityLog::EVENT_VISIT, $opened->copy()->addMinutes(1), '201.17.88.12');
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LESSON_VIEWED, $opened->copy()->addMinutes(4), '201.17.88.12', null, $material, $material->title);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED, $opened->copy()->addMinutes(5), '201.17.88.12', null, $material, 'Apostila Demo.pdf', 0);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED, $opened->copy()->addMinutes(6), '201.17.88.12', null, $material, 'Planilha de exercícios.xlsx', 1);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LESSON_COMPLETED, $opened->copy()->addMinutes(18), '201.17.88.12', null, $material, $material->title);
            MemberLessonProgress::query()->create([
                'user_id' => $student->id,
                'member_lesson_id' => $material->id,
                'product_id' => $product->id,
                'completed_at' => $opened->copy()->addMinutes(18),
                'progress_percent' => 100,
            ]);
        }

        if ($closing) {
            $today = Carbon::now()->subHours(3);
            $this->log($student, $product, MemberStudentActivityLog::EVENT_VISIT, $today, '187.12.44.91');
            $this->log($student, $product, MemberStudentActivityLog::EVENT_LESSON_VIEWED, $today->copy()->addMinutes(2), '187.12.44.91', null, $closing, $closing->title);
        }
    }

    private function log(
        User $student,
        Product $product,
        string $event,
        Carbon $at,
        string $ip,
        ?string $userAgent = 'Mozilla/5.0',
        ?MemberLesson $lesson = null,
        ?string $subject = null,
        ?int $fileIndex = null,
    ): void {
        $row = MemberStudentActivityLog::query()->create([
            'user_id' => $student->id,
            'product_id' => (string) $product->id,
            'member_lesson_id' => $lesson?->id,
            'event' => $event,
            'subject' => $subject ? Str::limit($subject, 255, '') : null,
            'file_index' => $fileIndex,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'metadata' => ['seed' => 'local_student_dossier'],
        ]);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
