<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformFiscalExportService;
use App\Services\Platform\PlatformFiscalReportService;
use App\Support\PlatformFiscalPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalController extends Controller
{
    public function index(Request $request, PlatformFiscalReportService $report): Response
    {
        $period = PlatformFiscalPeriod::fromRequest($request);
        [$sortBy, $sortDirection] = PlatformFiscalPeriod::normalizeSort(
            $request->query('sort_by'),
            $request->query('sort_direction')
        );

        return Inertia::render('Platform/Fiscal/Index', $report->page(
            $period,
            trim((string) $request->query('q', '')),
            $sortBy,
            $sortDirection,
            PlatformFiscalPeriod::normalizePerPage($request->query('per_page')),
            max(1, (int) $request->query('page', 1)),
        ));
    }

    public function exportCsv(Request $request, PlatformFiscalExportService $export): StreamedResponse
    {
        return $this->export($request, $export, 'csv');
    }

    public function exportXlsx(Request $request, PlatformFiscalExportService $export): StreamedResponse
    {
        return $this->export($request, $export, 'xlsx');
    }

    private function export(Request $request, PlatformFiscalExportService $export, string $format): StreamedResponse
    {
        $period = PlatformFiscalPeriod::fromRequest($request);
        [$sortBy, $sortDirection] = PlatformFiscalPeriod::normalizeSort(
            $request->query('sort_by'),
            $request->query('sort_direction')
        );

        return $export->download(
            $period,
            $format,
            trim((string) $request->query('q', '')),
            $sortBy,
            $sortDirection,
        );
    }
}
