<?php

namespace Tests\Unit;

use App\Services\ExcelReaderService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssemblyDelegateDateNormalizationTest extends TestCase
{
    private ExcelReaderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelReaderService;
    }

    #[DataProvider('twoDigitYearProvider')]
    public function test_normalize_two_digit_year_dmy_matches_iso_input(string $excelDate, string $expectedYmd): void
    {
        $this->assertSame($expectedYmd, $this->service->normalizeAssemblyDate($excelDate));
        $this->assertSame($expectedYmd, $this->service->normalizeAssemblyDate($expectedYmd));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function twoDigitYearProvider(): array
    {
        return [
            'dmY_short' => ['12/04/16', '2016-04-12'],
            'dmY_short_single_day' => ['8/4/16', '2016-04-08'],
            'four_digit_year_after_two_digit_rules' => ['30/06/1993', '1993-06-30'],
            'us_excel_four_digit_year' => ['1/14/2002', '2002-01-14'],
        ];
    }

    public function test_ambiguous_slash_date_matches_us_excel_and_iso_form(): void
    {
        $this->assertTrue($this->service->assemblyNormalizedDatesEquivalent('4/12/2016', '2016-04-12'));
        $candidates = $this->service->assemblyDateNormalizationCandidatesForMatch('4/12/2016');
        $this->assertContains('2016-04-12', $candidates);
        $this->assertContains('2016-12-04', $candidates);
    }

    public function test_unambiguous_day_gt_12_single_interpretation(): void
    {
        $this->assertTrue($this->service->assemblyNormalizedDatesEquivalent('30/06/1993', '1993-06-30'));
        $this->assertEquals(['1993-06-30'], $this->service->assemblyDateNormalizationCandidatesForMatch('30/06/1993'));
    }
}
