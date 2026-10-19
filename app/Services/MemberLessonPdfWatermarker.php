<?php

namespace App\Services;

use App\Exceptions\MemberPdfWatermarkException;
use App\Services\Pdf\SocialWatermarkDocument;
use Illuminate\Support\Facades\Log;

class MemberLessonPdfWatermarker
{
    /**
     * @param  array{name?: string, email?: string, cpf?: string|null, customer_number?: string}  $identity
     */
    public function stamp(string $pdfBinary, array $identity): string
    {
        $cpf = $this->formatDocument(isset($identity['cpf']) ? (string) $identity['cpf'] : null);
        $normalized = [
            'name' => $this->clean((string) ($identity['name'] ?? '')),
            'email' => $this->clean((string) ($identity['email'] ?? '')),
            'cpf' => $cpf ?? '',
            'has_cpf' => $cpf !== null,
            'customer_number' => $this->clean((string) ($identity['customer_number'] ?? '')),
        ];

        try {
            return (new SocialWatermarkDocument)->render($pdfBinary, $normalized);
        } catch (MemberPdfWatermarkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('member_pdf_watermark.stamp_failed', [
                'message' => $e->getMessage(),
            ]);

            throw new MemberPdfWatermarkException('Não foi possível proteger este PDF. Ele pode estar com senha ou em um formato incompatível. O arquivo original não foi enviado.');
        }
    }

    private function formatDocument(?string $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $document) ?? '';
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }

        $trimmed = $this->clean($document);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, 180);
    }
}
