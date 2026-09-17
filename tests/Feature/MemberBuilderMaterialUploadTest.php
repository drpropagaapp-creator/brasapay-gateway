<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberBuilderMaterialUploadTest extends TestCase
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

    public function test_upload_accepts_pdf_and_txt(): void
    {
        Storage::fake('public');
        [$owner, $product] = $this->sellerWithMemberProduct();

        $pdf = $this->actingAs($owner)->postJson(
            route('member-builder.upload-pdf', $product),
            ['file' => UploadedFile::fake()->createWithContent('aula.pdf', "%PDF-1.4\n% material")]
        );
        $pdf->assertOk();
        $this->assertStringEndsWith('.pdf', (string) $pdf->json('path'));
        Storage::disk('public')->assertExists($pdf->json('path'));

        $txt = $this->actingAs($owner)->postJson(
            route('member-builder.upload-pdf', $product),
            ['file' => UploadedFile::fake()->createWithContent('notas.txt', 'Texto da aula')]
        );
        $txt->assertOk();
        $this->assertStringEndsWith('.txt', (string) $txt->json('path'));
        Storage::disk('public')->assertExists($txt->json('path'));
    }

    public function test_upload_rejects_html_svg_and_javascript(): void
    {
        Storage::fake('public');
        [$owner, $product] = $this->sellerWithMemberProduct();

        $this->actingAs($owner)->postJson(
            route('member-builder.upload-pdf', $product),
            ['file' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>')]
        )->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->actingAs($owner)->postJson(
            route('member-builder.upload-pdf', $product),
            ['file' => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]
        )->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->actingAs($owner)->postJson(
            route('member-builder.upload-pdf', $product),
            ['file' => UploadedFile::fake()->createWithContent('app.js', 'alert(1)')]
        )->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    public function test_local_material_is_served_as_attachment(): void
    {
        $dirName = 'member-material-'.uniqid('', true);
        $dir = storage_path('app/public/'.$dirName);
        $this->assertTrue(mkdir($dir, 0777, true));
        $file = $dir.DIRECTORY_SEPARATOR.'notas.txt';
        file_put_contents($file, 'material seguro');

        try {
            $response = $this->get('/storage/'.$dirName.'/notas.txt');
            $response->assertOk();
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('content-type'));
            $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
            $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function sellerWithMemberProduct(): array
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
            'checkout_slug' => 'mbmat'.substr(uniqid('', true), -8),
            'slug' => 'mm-'.substr(uniqid('', true), -8),
        ]);

        return [$owner->fresh(), $product];
    }
}
