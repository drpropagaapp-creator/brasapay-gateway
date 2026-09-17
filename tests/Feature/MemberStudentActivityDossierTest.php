<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberStudentActivityLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberStudentActivityDossierTest extends TestCase
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

    public function test_member_area_login_and_visit_are_logged_with_ip(): void
    {
        [$owner, $product, $student] = $this->enrolledStudent();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('member-area.login.post', ['slug' => $product->checkout_slug]), [
                'email' => $student->email,
                'password' => 'password',
            ])->assertRedirect();

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'product_id' => (string) $product->id,
            'event' => MemberStudentActivityLog::EVENT_LOGIN,
            'ip' => '203.0.113.10',
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.show', ['slug' => $product->checkout_slug]))
            ->assertOk();

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'product_id' => (string) $product->id,
            'event' => MemberStudentActivityLog::EVENT_VISIT,
        ]);
    }

    public function test_opening_and_completing_lesson_writes_activity(): void
    {
        [$owner, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)
            ->get(route('member-area-app.lesson', ['slug' => $product->checkout_slug, 'lesson' => $lesson->id]))
            ->assertOk();

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'event' => MemberStudentActivityLog::EVENT_LESSON_VIEWED,
            'member_lesson_id' => $lesson->id,
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.lesson', ['slug' => $product->checkout_slug, 'lesson' => $lesson->id]))
            ->assertOk();

        $this->assertSame(1, MemberStudentActivityLog::query()
            ->where('user_id', $student->id)
            ->where('event', MemberStudentActivityLog::EVENT_LESSON_VIEWED)
            ->count());

        $this->actingAs($student)->postJson(route('member-area-app.lesson.complete', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
        ]))->assertOk();

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'event' => MemberStudentActivityLog::EVENT_LESSON_COMPLETED,
            'member_lesson_id' => $lesson->id,
        ]);
    }

    public function test_material_download_is_logged_and_redirects(): void
    {
        [$owner, $product, $student, $lesson] = $this->enrolledStudent();
        $lesson->update([
            'type' => MemberLesson::TYPE_PDF,
            'content_files' => [
                ['url' => 'https://cdn.example.com/aula.pdf', 'name' => 'Apostila.pdf'],
            ],
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.lesson.material', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
                'index' => 0,
            ]))
            ->assertRedirect('https://cdn.example.com/aula.pdf');

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'event' => MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED,
            'subject' => 'Apostila.pdf',
            'file_index' => 0,
        ]);
    }

    public function test_seller_can_open_student_dossier_and_other_seller_cannot(): void
    {
        [$owner, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)->postJson(route('member-area-app.lesson.complete', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
        ]))->assertOk();

        $dossier = $this->actingAs($owner)->getJson(route('alunos.dossier', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]));
        $dossier->assertOk();
        $dossier->assertJsonPath('product.id', $product->id);
        $events = $dossier->json('events');
        $this->assertIsArray($events);
        $this->assertTrue(collect($events)->contains(fn ($e) => ($e['event'] ?? '') === MemberStudentActivityLog::EVENT_LESSON_COMPLETED));
        $this->assertTrue(collect($events)->contains(fn ($e) => ($e['event'] ?? '') === MemberStudentActivityLog::EVENT_ENROLLED));

        $csv = $this->actingAs($owner)->get(route('alunos.dossier.export', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]));
        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString($student->email, $body);
        $this->assertStringContainsString('Data;Evento;IP', $body);
        $this->assertStringContainsString('Acesso concedido', $body);

        $pdf = $this->actingAs($owner)->get(route('alunos.dossier.export-pdf', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString(
            'attachment; filename="',
            (string) $pdf->headers->get('content-disposition')
        );

        $other = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $other->forceFill(['tenant_id' => $other->id])->save();

        $this->actingAs($other->fresh())->getJson(route('alunos.dossier', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]))->assertNotFound();

        $this->actingAs($other->fresh())->get(route('alunos.dossier.export', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]))->assertNotFound();

        $this->actingAs($other->fresh())->get(route('alunos.dossier.export-pdf', [
            'aluno' => $student->id,
            'produto' => $product->id,
        ]))->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Product, 2: User, 3: MemberLesson}
     */
    private function enrolledStudent(): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbact'.substr(uniqid('', true), -8),
            'slug' => 'mba'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => 'Conteúdo',
        ]);

        $student = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'password' => Hash::make('password'),
        ]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return [$owner->fresh(), $product, $student, $lesson];
    }
}
