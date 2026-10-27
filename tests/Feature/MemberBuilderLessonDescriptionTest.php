<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberBuilderLessonDescriptionTest extends TestCase
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

    public function test_store_keeps_plain_description_with_newlines_and_url(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $text = "Leia antes da aula.\n\nhttps://exemplo.com/material\nObrigado";

        $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Aula 1',
                'type' => MemberLesson::TYPE_VIDEO,
                'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
                'content_text' => $text,
            ]
        )->assertOk();

        $lesson = MemberLesson::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($lesson);
        $this->assertSame($text, $lesson->content_text);
    }

    public function test_updating_title_does_not_rewrite_existing_description(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $text = "Descrição original.\nLink: https://drive.google.com/file";
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula antiga',
            'position' => 1,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
            'content_text' => $text,
        ]);

        $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', [$product, $lesson]),
            [
                'title' => 'Aula antiga (novo título)',
                'type' => MemberLesson::TYPE_VIDEO,
                'content_url' => $lesson->content_url,
                'content_text' => $text,
            ]
        )->assertOk();

        $fresh = $lesson->fresh();
        $this->assertSame('Aula antiga (novo título)', $fresh->title);
        $this->assertSame($text, $fresh->content_text);
    }

    public function test_updating_other_fields_without_identical_description_payload_still_skips_when_sanitizer_is_noop(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $text = "Linha 1\nLinha 2";
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF,
            'content_url' => 'https://example.com/a.pdf',
            'content_text' => $text,
            'watermark_enabled' => false,
        ]);

        $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', [$product, $lesson]),
            [
                'title' => 'Aula',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => 'https://example.com/b.pdf',
                'content_text' => $text,
                'watermark_enabled' => false,
            ]
        )->assertOk();

        $this->assertSame($text, $lesson->fresh()->content_text);
        $this->assertSame('https://example.com/b.pdf', $lesson->fresh()->content_url);
    }

    public function test_store_persists_formatted_description_and_strips_script(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $html = '<p>Veja o <strong>material</strong> e <a href="https://exemplo.com/x.pdf">Baixar material</a>.</p><script>alert(1)</script>';

        $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Aula formatada',
                'type' => MemberLesson::TYPE_VIDEO,
                'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
                'content_text' => $html,
            ]
        )->assertOk();

        $stored = MemberLesson::query()->where('product_id', $product->id)->value('content_text');
        $this->assertStringContainsString('<strong>material</strong>', $stored);
        $this->assertStringContainsString('Baixar material', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_student_receives_original_plain_description(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $text = "Bem-vindo.\n\nhttps://exemplo.com/aula";
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
            'content_text' => $text,
        ]);

        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.module', [
                'slug' => $product->checkout_slug,
                'module' => $module->id,
                'aula' => $lesson->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/ModuleContent')
                ->where('current_lesson.content_text', $text)
            );
    }

    /**
     * @return array{0: User, 1: Product, 2: MemberModule}
     */
    private function sellerWithModule(): array
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
            'checkout_slug' => 'mbdesc'.substr(uniqid('', true), -8),
            'slug' => 'mdc-'.substr(uniqid('', true), -8),
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

        return [$owner->fresh(), $product, $module];
    }
}
