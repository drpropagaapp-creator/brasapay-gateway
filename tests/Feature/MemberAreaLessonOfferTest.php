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

class MemberAreaLessonOfferTest extends TestCase
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

    public function test_module_content_omits_offer_when_disabled(): void
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
                ->where('lesson_offer', null)
            );
    }

    public function test_module_content_exposes_offer_until_student_owns_the_product(): void
    {
        [, $product, $student, $lesson, $related] = $this->enrolledStudent(withOffer: true);

        $this->actingAs($student)
            ->get(route('member-area-app.module', [
                'slug' => $product->checkout_slug,
                'module' => $lesson->member_module_id,
                'aula' => $lesson->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/ModuleContent')
                ->where('lesson_offer.product_id', (string) $related->id)
                ->where('lesson_offer.checkout_slug', $related->checkout_slug)
                ->where('lesson_offer.checkout_url', url('/c/'.$related->checkout_slug))
                ->where('lesson_offer.button_text', 'Garantir acesso')
            );

        DB::table('product_user')->insert([
            'product_id' => $related->id,
            'user_id' => $student->id,
            'created_at' => now(),
            'updated_at' => now(),
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
                ->where('lesson_offer', null)
            );
    }

    public function test_builder_saves_lesson_offer_for_tenant_product_only(): void
    {
        [$owner, $product, , , $related] = $this->enrolledStudent();
        $otherOwner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $otherOwner->forceFill(['tenant_id' => $otherOwner->id])->save();
        $outsider = $this->createTestProduct([
            'tenant_id' => $otherOwner->id,
            'checkout_slug' => 'out'.bin2hex(random_bytes(4)),
            'slug' => 'out'.bin2hex(random_bytes(4)),
        ]);

        $this->actingAs($owner)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), [
                'member_area_config' => array_replace_recursive($product->member_area_config ?? [], [
                    'lesson_offer' => [
                        'enabled' => true,
                        'product_id' => (string) $related->id,
                        'button_text' => 'Comprar agora',
                    ],
                ]),
            ])
            ->assertOk();

        $product->refresh();
        $this->assertTrue((bool) ($product->member_area_config['lesson_offer']['enabled'] ?? false));
        $this->assertSame((string) $related->id, (string) $product->member_area_config['lesson_offer']['product_id']);
        $this->assertSame('Comprar agora', $product->member_area_config['lesson_offer']['button_text']);

        $this->actingAs($owner)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), [
                'member_area_config' => array_replace_recursive($product->member_area_config ?? [], [
                    'lesson_offer' => [
                        'enabled' => true,
                        'product_id' => (string) $outsider->id,
                        'button_text' => 'Não deve salvar',
                    ],
                ]),
            ])
            ->assertOk();

        $product->refresh();
        $this->assertFalse((bool) ($product->member_area_config['lesson_offer']['enabled'] ?? true));
        $this->assertTrue(empty($product->member_area_config['lesson_offer']['product_id']));
    }

    /**
     * @return array{0: User, 1: Product, 2: User, 3: MemberLesson, 4: Product}
     */
    private function enrolledStudent(bool $withOffer = false): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $related = $this->createTestProduct([
            'tenant_id' => $owner->id,
            'checkout_slug' => 'offr'.bin2hex(random_bytes(4)),
            'slug' => 'offr'.bin2hex(random_bytes(4)),
            'price' => 97,
            'is_active' => true,
        ]);

        $config = Product::defaultMemberAreaConfig();
        if ($withOffer) {
            $config['lesson_offer'] = [
                'enabled' => true,
                'product_id' => (string) $related->id,
                'button_text' => 'Garantir acesso',
            ];
        }

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'moff'.bin2hex(random_bytes(4)),
            'slug' => 'moff'.bin2hex(random_bytes(4)),
            'member_area_config' => $config,
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

        return [$owner->fresh(), $product, $student, $lesson->fresh(['module']), $related];
    }
}
