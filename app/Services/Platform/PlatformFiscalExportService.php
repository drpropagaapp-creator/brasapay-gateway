<?php

namespace App\Services\Platform;

use App\Support\PlatformFiscalPeriod;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformFiscalExportService
{
    public function __construct(
        private readonly PlatformFiscalReportService $report,
    ) {}

    public function download(
        PlatformFiscalPeriod $period,
        string $format,
        string $search = '',
        string $sortBy = 'total_fees',
        string $sortDirection = 'desc',
    ): StreamedResponse {
        $format = strtolower($format) === 'xlsx' ? 'xlsx' : 'csv';
        $generatedAt = Carbon::now()->format('d/m/Y H:i:s');
        $cards = $this->report->cards($period->start, $period->end);
        $rows = $this->report->sellerRows($period->start, $period->end, $search, $sortBy, $sortDirection);

        $meta = [
            ['Relatório Fiscal'],
            ['Período', $period->label()],
            ['Início', $period->start],
            ['Fim', $period->end],
            ['Gerado em', $generatedAt],
            [],
        ];

        $headers = [
            'Infoprodutor',
            'CPF/CNPJ',
            'Quantidade de Vendas',
            'Volume Vendido',
            'Taxas de Venda',
            'Quantidade de Saques',
            'Taxas de Saque',
            'Total em Taxas',
        ];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['name'],
                $row['document'] !== '' ? $row['document'] : '—',
                $row['sales_count'],
                $this->money($row['volume']),
                $this->money($row['sale_fees']),
                $row['withdrawals_count'],
                $this->money($row['withdrawal_fees']),
                $this->money($row['total_fees']),
            ];
        }

        $data[] = [
            'TOTAL DO PERÍODO',
            '',
            $cards['sales_count'],
            $this->money($cards['volume']),
            $this->money($cards['sale_fees']),
            $cards['withdrawals_count'],
            $this->money($cards['withdrawal_fees']),
            $this->money($cards['total_fees']),
        ];

        $filename = $period->filenameStem().'.'.$format;

        return $format === 'xlsx'
            ? $this->streamXlsx($meta, $headers, $data, $filename)
            : $this->streamCsv($meta, $headers, $data, $filename);
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    /**
     * @param  list<list<mixed>>  $meta
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function streamCsv(array $meta, array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($meta, $headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($meta as $line) {
                fputcsv($out, $line, ';');
            }
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<list<mixed>>  $meta
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function streamXlsx(array $meta, array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($meta, $headers, $rows) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Fiscal');

            $r = 1;
            foreach ($meta as $line) {
                foreach ($line as $c => $value) {
                    $sheet->setCellValue([$c + 1, $r], $value);
                }
                $r++;
            }

            $headerRow = $r;
            foreach ($headers as $c => $value) {
                $sheet->setCellValue([$c + 1, $headerRow], $value);
            }
            $sheet->getStyle($headerRow.':'.$headerRow)->getFont()->setBold(true);

            $r = $headerRow + 1;
            foreach ($rows as $row) {
                foreach ($row as $c => $value) {
                    $sheet->setCellValue([$c + 1, $r], $value);
                }
                $r++;
            }

            $lastRow = $r - 1;
            if ($lastRow >= $headerRow) {
                $sheet->getStyle($lastRow.':'.$lastRow)->getFont()->setBold(true);
            }

            foreach (range(1, count($headers)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
