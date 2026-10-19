<?php

namespace App\Services\Pdf;

use App\Exceptions\MemberPdfWatermarkException;
use App\Support\UploadLimits;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Copia cada página do PDF e grava a identificação do comprador por cima.
 */
class SocialWatermarkDocument extends Fpdi
{
    private const MAX_PAGES = 250;

    /** @var array<int, array{ca: float, CA: float, BM: string, n: int}> */
    private array $extGStates = [];

    /** @var array<string, int> */
    private array $alphaIds = [];

    private float $watermarkAngle = 0;

    /**
     * @param  array{name: string, email: string, cpf: string, customer_number: string, has_cpf: bool}  $identity
     */
    public function render(string $pdfBinary, array $identity): string
    {
        if (! str_starts_with($pdfBinary, '%PDF')) {
            throw new MemberPdfWatermarkException('O arquivo não é um PDF válido. O original não foi enviado.');
        }

        $maxBytes = UploadLimits::memberBuilderPdfMaxKb() * 1024;
        if (strlen($pdfBinary) > $maxBytes) {
            throw new MemberPdfWatermarkException('Este PDF passa do tamanho máximo permitido para proteção.');
        }

        $this->SetCompression(true);
        $this->SetAutoPageBreak(false);
        $this->WithAlpha = true;
        if ($this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }

        $pageCount = $this->setSourceFile(StreamReader::createByString($pdfBinary));
        if ($pageCount < 1) {
            throw new MemberPdfWatermarkException('O PDF não tem páginas para proteger. O original não foi enviado.');
        }
        if ($pageCount > self::MAX_PAGES) {
            throw new MemberPdfWatermarkException('Este PDF tem páginas demais para receber a proteção. Divida o arquivo em partes menores.');
        }

        $name = $this->latin($identity['name'] !== '' ? $identity['name'] : 'Aluno');
        $email = $this->latin($identity['email'] !== '' ? $identity['email'] : 'sem e-mail');
        $cpf = $this->latin($identity['has_cpf'] ? $identity['cpf'] : 'não informado');
        $number = $this->latin($identity['customer_number'] !== '' ? $identity['customer_number'] : 'não informado');
        $header = $name."\n".$this->latin('CPF: ').$cpf.'   '.$email."\n".$this->latin('Nº do cliente: ').$number;
        $footer = $this->latin('Licença pessoal e intransferível. Este arquivo foi gerado para o comprador identificado acima. Copiar, repassar ou publicar este material viola a licença e permite identificar o responsável.');
        $diagonalSecondary = $identity['has_cpf']
            ? $this->latin('CPF ').$cpf
            : $email;

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $template = $this->importPage($pageNo);
            $size = $this->getTemplateSize($template);
            if (! is_array($size)) {
                throw new MemberPdfWatermarkException('Não foi possível ler uma página deste PDF. O original não foi enviado.');
            }

            $width = (float) $size['width'];
            $height = (float) $size['height'];
            $this->AddPage($size['orientation'], [$width, $height]);
            $this->useTemplate($template);
            $this->drawMarks($width, $height, $name, $diagonalSecondary, $header, $footer);
        }

        $binary = $this->Output('S');
        $this->assertDeliverablePdf($binary);

        return $binary;
    }

    private function assertDeliverablePdf(string $binary): void
    {
        $trimmed = ltrim($binary, "\xEF\xBB\xBF");
        if (! str_starts_with($trimmed, '%PDF') || ! str_contains($binary, '%%EOF')) {
            throw new MemberPdfWatermarkException('Não foi possível proteger este PDF. O arquivo original não foi enviado.');
        }

        try {
            $check = new Fpdi;
            $pages = $check->setSourceFile(StreamReader::createByString($binary));
        } catch (\Throwable $e) {
            throw new MemberPdfWatermarkException('Não foi possível proteger este PDF. O arquivo original não foi enviado.');
        }

        if ($pages < 1) {
            throw new MemberPdfWatermarkException('Não foi possível proteger este PDF. O arquivo original não foi enviado.');
        }
    }

    private function drawMarks(
        float $pageW,
        float $pageH,
        string $diagonalPrimary,
        string $diagonalSecondary,
        string $header,
        string $footer,
    ): void {
        $this->drawDiagonal($pageW, $pageH, $diagonalPrimary, $diagonalSecondary);

        $band = $pageH >= 80 ? 16.0 : 10.0;
        if (($band * 2) >= $pageH) {
            return;
        }

        $this->SetFillColor(255, 255, 255);
        $this->Rect(0, 0, $pageW, $band, 'F');
        $this->Rect(0, $pageH - $band, $pageW, $band, 'F');

        $this->SetTextColor(20, 20, 20);
        $this->SetFont('Arial', '', $pageW >= 140 ? 8 : 6);
        $this->SetXY(4, 1.6);
        $this->MultiCell(max(10, $pageW - 8), 3.5, $header, 0, 'L');

        $this->SetFont('Arial', '', $pageW >= 140 ? 7 : 6);
        $this->SetTextColor(40, 40, 40);
        $this->SetXY(4, $pageH - $band + 1.8);
        $this->MultiCell(max(10, $pageW - 8), 3.2, $footer, 0, 'L');
    }

    private function drawDiagonal(float $pageW, float $pageH, string $line1, string $line2): void
    {
        $size = 34;
        $this->SetFont('Arial', 'B', $size);
        $width = max($this->GetStringWidth($line1), $this->GetStringWidth($line2));
        $limit = hypot($pageW, $pageH) * 0.62;
        while ($width > $limit && $size > 14) {
            $size -= 2;
            $this->SetFont('Arial', 'B', $size);
            $width = max($this->GetStringWidth($line1), $this->GetStringWidth($line2));
        }

        $centerX = $pageW / 2;
        $centerY = $pageH / 2;
        $lineGap = max(6, $size * 0.45);

        $this->rotate(32, $centerX, $centerY);
        $this->applyAlpha(0.34);
        $this->SetTextColor(30, 30, 30);
        $this->Text($centerX - ($this->GetStringWidth($line1) / 2), $centerY - ($lineGap / 2), $line1);
        $this->Text($centerX - ($this->GetStringWidth($line2) / 2), $centerY + $lineGap, $line2);
        $this->applyAlpha(1);
        $this->rotate(0, $centerX, $centerY);
    }

    private function applyAlpha(float $alpha): void
    {
        $alpha = max(0, min(1, $alpha));
        $key = number_format($alpha, 3, '.', '');
        if (! isset($this->alphaIds[$key])) {
            $id = count($this->extGStates) + 1;
            $this->extGStates[$id] = [
                'ca' => $alpha,
                'CA' => $alpha,
                'BM' => '/Normal',
                'n' => 0,
            ];
            $this->alphaIds[$key] = $id;
        }

        $this->_out(sprintf('/GS%d gs', $this->alphaIds[$key]));
    }

    private function rotate(float $angle, float $x, float $y): void
    {
        if ($this->watermarkAngle != 0.0) {
            $this->_out('Q');
        }
        $this->watermarkAngle = $angle;
        if ($angle == 0.0) {
            return;
        }

        $angle *= M_PI / 180;
        $c = cos($angle);
        $s = sin($angle);
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;
        $this->_out(sprintf(
            'q %.5F %.5F %.5F %.5F %.5F %.5F cm 1 0 0 1 %.5F %.5F cm',
            $c,
            $s,
            -$s,
            $c,
            $cx,
            $cy,
            -$cx,
            -$cy
        ));
    }

    protected function _endpage()
    {
        if ($this->watermarkAngle != 0.0) {
            $this->watermarkAngle = 0;
            $this->_out('Q');
        }

        parent::_endpage();
    }

    protected function _putresources()
    {
        $this->putExtGStates();
        parent::_putresources();
    }

    protected function _putresourcedict()
    {
        parent::_putresourcedict();
        if ($this->extGStates === []) {
            return;
        }

        $this->_put('/ExtGState <<');
        foreach ($this->extGStates as $id => $state) {
            $this->_put('/GS'.$id.' '.$state['n'].' 0 R');
        }
        $this->_put('>>');
    }

    private function putExtGStates(): void
    {
        foreach ($this->extGStates as $id => $state) {
            $this->_newobj();
            $this->extGStates[$id]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            $this->_put(sprintf('/ca %.3F', $state['ca']));
            $this->_put(sprintf('/CA %.3F', $state['CA']));
            $this->_put('/BM '.$state['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    private function latin(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }
}
