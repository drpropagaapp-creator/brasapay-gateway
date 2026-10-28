<?php

namespace Tests\Feature;

use App\Support\LogoTrimBox;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoTrimBoxTest extends TestCase
{
    private function squareLogoWithBand(): string
    {
        // 400×400 transparente com o desenho numa faixa 300×60 (como os logos enviados no admin).
        $img = imagecreatetruecolor(400, 400);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 50, 170, 349, 229, imagecolorallocatealpha($img, 255, 120, 0, 0));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function test_recorta_a_margem_transparente_lendo_do_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('white-label/logo.png', $this->squareLogoWithBand());

        $box = LogoTrimBox::for('/storage/white-label/logo.png');

        $this->assertSame(['x' => 50, 'y' => 170, 'w' => 300, 'h' => 60, 'nw' => 400, 'nh' => 400], $box);
    }

    public function test_url_de_storage_remoto_usa_a_chave_do_objeto(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('white-label/remoto.png', $this->squareLogoWithBand());

        $boxes = LogoTrimBox::boxes(['https://pub-exemplo.r2.dev/white-label/remoto.png', '', null, 'https://cdn.exemplo.com/foto.jpg']);

        $this->assertSame(['https://pub-exemplo.r2.dev/white-label/remoto.png'], array_keys($boxes));
        $this->assertSame(300, $boxes['https://pub-exemplo.r2.dev/white-label/remoto.png']['w']);
    }

    public function test_arquivo_inexistente_ou_caminho_suspeito_nao_quebra(): void
    {
        Storage::fake('public');

        $this->assertNull(LogoTrimBox::for('/storage/white-label/nao-existe.png'));
        $this->assertNull(LogoTrimBox::for('/storage/../.env.png'));
        $this->assertSame([], LogoTrimBox::boxes(['/storage/white-label/nao-existe.png']));
    }
}
