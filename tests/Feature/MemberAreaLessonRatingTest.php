<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberLesson;
use App\Models\MemberLessonRating;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberAreaLessonRatingTest extends TestCase
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

    public function test_module_content_exposes_null_rating_when_student_has_not_voted(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)
            ->get(route('member-area-app.module', [
                'slug' => $product->checkout_slug,
                'module' => $lesson->member_module_id,
                'aula' => $lesson->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/ModuleContent')
                ->where('current_lesson.id', $lesson->id)
                ->where('current_lesson.rating', null)
            );
    }

    public function test_student_can_rate_a_lesson_and_change_the_vote(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)
            ->postJson(route('member-area-app.lesson.rating', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]), ['rating' => 4])
            ->assertOk()
            ->assertJson(['success' => true, 'rating' => 4]);

        $this->assertDatabaseHas('member_lesson_ratings', [
            'user_id' => $student->id,
            'member_lesson_id' => $lesson->id,
            'product_id' => (string) $product->id,
            'rating' => 4,
        ]);

        $this->actingAs($student)
            ->postJson(route('member-area-app.lesson.rating', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]), ['rating' => 5])
            ->assertOk()
            ->assertJson(['success' => true, 'rating' => 5]);

        $this->assertSame(1, MemberLessonRating::query()->where('user_id', $student->id)->where('member_lesson_id', $lesson->id)->count());
        $this->assertSame(5, (int) MemberLessonRating::query()->where('user_id', $student->id)->where('member_lesson_id', $lesson->id)->value('rating'));
    }

    public function test_module_content_exposes_the_student_rating_only(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();
        $other = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $other->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        MemberLessonRating::create([
            'user_id' => $other->id,
            'member_lesson_id' => $lesson->id,
            'product_id' => (string) $product->id,
            'rating' => 2,
        ]);
        MemberLessonRating::create([
            'user_id' => $student->id,
            'member_lesson_id' => $lesson->id,
            'product_id' => (string) $product->id,
            'rating' => 5,
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.module', [
                'slug' => $product->checkout_slug,
                'module' => $lesson->member_module_id,
                'aula' => $lesson->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/ModuleContent')
                ->where('current_lesson.rating', 5)
            );
    }

    public function test_invalid_rating_is_rejected(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)
            ->postJson(route('member-area-app.lesson.rating', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]), ['rating' => 6])
            ->assertUnprocessable();

        $this->assertSame(0, MemberLessonRating::query()->count());
    }

    public function test_locked_lesson_cannot_be_rated(): void
    {
        [, $product, $student, $unlocked] = $this->enrolledStudent();
        $locked = MemberLesson::create([
            'member_module_id' => $unlocked->member_module_id,
            'product_id' => $product->id,
            'title' => 'Aula bloqueada',
            'position' => 2,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
            'release_after_days' => 30,
        ]);

        $this->actingAs($student)
            ->postJson(route('member-area-app.lesson.rating', [
                'slug' => $product->checkout_slug,
                'lesson' => $locked->id,
            ]), ['rating' => 3])
            ->assertForbidden();

        $this->assertSame(0, MemberLessonRating::query()->count());
    }

    public function test_admin_preview_cannot_rate_a_lesson(): void
    {
        [, $product, , $lesson] = $this->enrolledStudent();
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('member-area-app.lesson.rating', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]), ['rating' => 5])
            ->assertForbidden();

        $this->assertSame(0, MemberLessonRating::query()->count());
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
            'checkout_slug' => 'mrate'.bin2hex(random_bytes(4)),
            'slug' => 'mrate'.bin2hex(random_bytes(4)),
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
        ]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return [$owner->fresh(), $product, $student, $lesson->fresh(['module'])];
    }
}
