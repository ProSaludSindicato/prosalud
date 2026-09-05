<?php

namespace Tests\Unit;

use App\Support\ConvenioSemesterPeriod;
use Carbon\Carbon;
use Tests\TestCase;

class ConvenioSemesterPeriodTest extends TestCase
{
    public function test_from_date_maps_january_to_first_semester(): void
    {
        $this->assertSame('20261', ConvenioSemesterPeriod::fromDate(Carbon::parse('2026-01-15')));
        $this->assertSame('20261', ConvenioSemesterPeriod::fromDate(Carbon::parse('2026-06-30')));
    }

    public function test_from_date_maps_july_to_second_semester(): void
    {
        $this->assertSame('20262', ConvenioSemesterPeriod::fromDate(Carbon::parse('2026-07-01')));
        $this->assertSame('20262', ConvenioSemesterPeriod::fromDate(Carbon::parse('2026-12-31')));
    }

    public function test_is_valid_accepts_year_and_semester(): void
    {
        $this->assertTrue(ConvenioSemesterPeriod::isValid('20261'));
        $this->assertTrue(ConvenioSemesterPeriod::isValid('20262'));
        $this->assertFalse(ConvenioSemesterPeriod::isValid('20263'));
        $this->assertFalse(ConvenioSemesterPeriod::isValid('todos'));
        $this->assertFalse(ConvenioSemesterPeriod::isValid(null));
    }

    public function test_date_range_covers_full_semester(): void
    {
        $first = ConvenioSemesterPeriod::dateRange('20261');
        $this->assertNotNull($first);
        $this->assertSame('2026-01-01 00:00:00', $first['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-30 23:59:59', $first['end']->format('Y-m-d H:i:s'));

        $second = ConvenioSemesterPeriod::dateRange('20262');
        $this->assertNotNull($second);
        $this->assertSame('2026-07-01 00:00:00', $second['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 23:59:59', $second['end']->format('Y-m-d H:i:s'));
    }

    public function test_label_uses_spanish_semester_names(): void
    {
        $this->assertSame('1.er semestre 2026', ConvenioSemesterPeriod::label('20261'));
        $this->assertSame('2.º semestre 2026', ConvenioSemesterPeriod::label('20262'));
        $this->assertSame('Todos los semestres', ConvenioSemesterPeriod::label('todos'));
    }

    public function test_previous_and_next_cross_year_boundary(): void
    {
        $this->assertSame('20252', ConvenioSemesterPeriod::previous('20261'));
        $this->assertSame('20262', ConvenioSemesterPeriod::next('20261'));
        $this->assertSame('20271', ConvenioSemesterPeriod::next('20262'));
    }

    public function test_available_includes_current_and_fills_gap_semesters(): void
    {
        $periods = ConvenioSemesterPeriod::available(
            Carbon::parse('2025-11-01'),
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-09-04'),
        );

        $this->assertSame(['20262', '20261', '20252'], $periods);
    }
}
