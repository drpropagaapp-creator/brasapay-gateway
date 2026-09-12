<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Intervalo de competência do relatório Fiscal (timezone da aplicação).
 */
final class PlatformFiscalPeriod
{
    public const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'personalizado'];

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    public const SORT_COLUMNS = [
        'name',
        'sales_count',
        'volume',
        'sale_fees',
        'withdrawals_count',
        'withdrawal_fees',
        'total_fees',
    ];

    public const DEFAULT_SORT = 'total_fees';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    /** @var array<int, string> */
    public const MONTH_SLUGS = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'marco',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    /** @var array<int, string> */
    public const MONTH_LABELS = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    public function __construct(
        public readonly string $period,
        public readonly string $start,
        public readonly string $end,
        public readonly int $year,
        public readonly ?int $month,
        public readonly ?string $fromDate,
        public readonly ?string $toDate,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $now = Carbon::now();
        $period = self::normalizePeriod($request->query('period', 'mes'));
        $year = self::normalizeYear($request->query('year'), $now->year);
        $month = self::normalizeMonth($request->query('month'), $now->month);
        $fromDate = self::normalizeDate($request->query('from'));
        $toDate = self::normalizeDate($request->query('to'));

        if ($period === 'personalizado' && ($fromDate === null || $toDate === null)) {
            $fromDate = $fromDate ?? $now->toDateString();
            $toDate = $toDate ?? $now->toDateString();
        }

        [$start, $end] = self::range($period, $now, $year, $month, $fromDate, $toDate);

        return new self($period, $start, $end, $year, $month, $fromDate, $toDate);
    }

    public static function normalizePeriod(mixed $period): string
    {
        $period = is_string($period) ? $period : 'mes';

        return in_array($period, self::PERIODS, true) ? $period : 'mes';
    }

    public static function normalizePerPage(mixed $perPage): int
    {
        $perPage = (int) $perPage;
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            return self::DEFAULT_PER_PAGE;
        }

        return $perPage;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function normalizeSort(mixed $sortBy, mixed $sortDirection): array
    {
        $sortBy = is_string($sortBy) ? $sortBy : self::DEFAULT_SORT;
        if (! in_array($sortBy, self::SORT_COLUMNS, true)) {
            $sortBy = self::DEFAULT_SORT;
        }
        $sortDirection = is_string($sortDirection) ? strtolower($sortDirection) : self::DEFAULT_SORT_DIRECTION;
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = self::DEFAULT_SORT_DIRECTION;
        }
        if ($sortBy === self::DEFAULT_SORT && $sortDirection === '') {
            $sortDirection = self::DEFAULT_SORT_DIRECTION;
        }

        return [$sortBy, $sortDirection];
    }

    public function queryParams(): array
    {
        $params = ['period' => $this->period];
        if ($this->period === 'mes') {
            $params['month'] = $this->month;
            $params['year'] = $this->year;
        } elseif ($this->period === 'ano') {
            $params['year'] = $this->year;
        } elseif ($this->period === 'personalizado') {
            $params['from'] = $this->fromDate;
            $params['to'] = $this->toDate;
        }

        return $params;
    }

    public function label(): string
    {
        return match ($this->period) {
            'hoje' => 'Hoje',
            'ontem' => 'Ontem',
            '7dias' => 'Últimos 7 dias',
            'mes' => (self::MONTH_LABELS[$this->month] ?? 'Mês').' / '.$this->year,
            'ano' => (string) $this->year,
            'personalizado' => ($this->fromDate ?? '').' a '.($this->toDate ?? ''),
            default => $this->period,
        };
    }

    public function filenameStem(): string
    {
        $start = Carbon::parse($this->start);

        return match ($this->period) {
            'mes' => sprintf(
                'fiscal-%04d-%02d-%s',
                $this->year,
                (int) $this->month,
                self::MONTH_SLUGS[$this->month] ?? 'mes'
            ),
            'ano' => 'fiscal-'.$this->year,
            default => 'fiscal-'.$start->format('Y-m-d'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function range(
        string $period,
        ?Carbon $now = null,
        ?int $year = null,
        ?int $month = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $now = $now ?? Carbon::now();
        $year = $year ?? $now->year;
        $month = $month ?? $now->month;

        switch ($period) {
            case 'hoje':
                return [
                    $now->copy()->startOfDay()->toDateTimeString(),
                    $now->copy()->toDateTimeString(),
                ];
            case 'ontem':
                $yesterday = $now->copy()->subDay();

                return [
                    $yesterday->copy()->startOfDay()->toDateTimeString(),
                    $yesterday->copy()->endOfDay()->toDateTimeString(),
                ];
            case '7dias':
                return [
                    $now->copy()->subDays(6)->startOfDay()->toDateTimeString(),
                    $now->copy()->toDateTimeString(),
                ];
            case 'ano':
                $cursor = $now->copy()->year($year);

                return [
                    $cursor->copy()->startOfYear()->toDateTimeString(),
                    $cursor->copy()->endOfYear()->toDateTimeString(),
                ];
            case 'personalizado':
                $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : $now->copy()->startOfDay();
                $to = $toDate ? Carbon::parse($toDate)->endOfDay() : $now->copy()->endOfDay();
                if ($to->lt($from)) {
                    [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
                }

                return [
                    $from->toDateTimeString(),
                    $to->toDateTimeString(),
                ];
            case 'mes':
            default:
                $cursor = $now->copy()->year($year)->month($month);

                return [
                    $cursor->copy()->startOfMonth()->toDateTimeString(),
                    $cursor->copy()->endOfMonth()->toDateTimeString(),
                ];
        }
    }

    private static function normalizeYear(mixed $year, int $fallback): int
    {
        $year = (int) $year;
        $min = 2020;
        $max = Carbon::now()->year + 1;
        if ($year < $min || $year > $max) {
            return $fallback;
        }

        return $year;
    }

    private static function normalizeMonth(mixed $month, int $fallback): int
    {
        $month = (int) $month;
        if ($month < 1 || $month > 12) {
            return $fallback;
        }

        return $month;
    }

    private static function normalizeDate(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
