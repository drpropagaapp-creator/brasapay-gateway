<?php

namespace Tests\Unit;

use App\Support\PlatformFiscalPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class PlatformFiscalPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hoje_runs_from_start_of_day_until_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:32:00', 'America/Sao_Paulo'));
        [$start, $end] = PlatformFiscalPeriod::range('hoje');

        $this->assertSame('2026-09-10 00:00:00', $start);
        $this->assertSame('2026-09-10 19:32:00', $end);
    }

    public function test_month_and_year_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'America/Sao_Paulo'));
        $period = PlatformFiscalPeriod::fromRequest(Request::create('/plataforma/fiscal', 'GET', [
            'period' => 'mes',
            'month' => 8,
            'year' => 2026,
        ]));

        $this->assertSame('2026-08-01 00:00:00', $period->start);
        $this->assertSame('2026-08-31 23:59:59', $period->end);
        $this->assertSame('fiscal-2026-08-agosto', $period->filenameStem());
    }
}
