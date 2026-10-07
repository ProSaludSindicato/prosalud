<?php

namespace Tests\Feature;

use App\Models\SstDeliveryRecord;
use App\Models\SstReturnRecord;
use App\Services\SstDeliveryReportService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SstDeliveryReportHospitalBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $affiliatesByDocument  Keyed by "TYPE-NUMBER".
     */
    private function mockDotacionService(array $affiliatesByDocument): void
    {
        $this->mock(SstDotacionService::class, function (MockInterface $mock) use ($affiliatesByDocument) {
            // Hospitals must be resolved per document from a convenio-bearing source; the bulk
            // summary catalog carries 'convenios' => [] and would always yield 'SIN ASIGNAR'.
            $mock->shouldReceive('findAffiliateWithConvenios')
                ->andReturnUsing(fn (string $type, string $number) => $affiliatesByDocument[$type.'-'.$number] ?? null);

            $mock->shouldReceive('getInventoryItems')->andReturn([]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function affiliate(string $documentNumber, string $hospital, ?string $role = 'BACTERIOLOGO(A)'): array
    {
        return [
            'id' => 'CC-'.$documentNumber,
            'firstName' => 'JUAN',
            'lastName' => 'PEREZ',
            'documentType' => 'CC',
            'documentNumber' => $documentNumber,
            'hospital' => $hospital,
            'role' => $role,
            'active' => true,
            'status' => 'ACTIVO',
        ];
    }

    private function createDelivery(string $documentNumber, ?string $hospital, ?string $role = null): SstDeliveryRecord
    {
        return SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-'.$documentNumber,
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => $documentNumber,
            'affiliate_first_name' => 'JUAN',
            'affiliate_last_name' => 'PEREZ',
            'affiliate_hospital' => $hospital,
            'affiliate_role' => $role,
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => $documentNumber,
            'delivery_type' => 'first_time',
        ]);
    }

    private function createReturn(string $documentNumber, ?string $hospital, ?string $role = null): SstReturnRecord
    {
        return SstReturnRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-'.$documentNumber,
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => $documentNumber,
            'affiliate_first_name' => 'JUAN',
            'affiliate_last_name' => 'PEREZ',
            'affiliate_hospital' => $hospital,
            'affiliate_role' => $role,
            'received_by_name' => 'Usuario Prueba',
            'returned_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => $documentNumber,
            'reason' => 'replacement',
        ]);
    }

    public function test_backfill_assigns_hospital_from_affiliate_source_to_records_without_one(): void
    {
        $placeholder = $this->createDelivery('111', 'SIN ASIGNAR');
        $empty = $this->createDelivery('222', '');
        $null = $this->createReturn('333', null);

        $this->mockDotacionService([
            'CC-111' => $this->affiliate('111', 'HMFS - BELLO'),
            'CC-222' => $this->affiliate('222', 'HOSPITAL MARCO FIDEL SUAREZ'),
            'CC-333' => $this->affiliate('333', 'CLÍNICA NORTE'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(3, $stats['pendingDocuments']);
        $this->assertSame(3, $stats['resolvedDocuments']);
        $this->assertSame(3, $stats['updatedRecords']);

        $this->assertSame('HMFS - BELLO', $placeholder->fresh()->affiliate_hospital);
        $this->assertSame('HOSPITAL MARCO FIDEL SUAREZ', $empty->fresh()->affiliate_hospital);
        $this->assertSame('CLÍNICA NORTE', $null->fresh()->affiliate_hospital);
    }

    /**
     * Regression guard: the bulk summary catalog maps every affiliate with 'convenios' => [], so
     * using it as a hospital source silently degrades every record to 'SIN ASIGNAR'. The backfill
     * must resolve per document through a convenio-bearing source and never touch the catalog.
     */
    public function test_backfill_never_resolves_hospitals_from_the_bulk_catalog(): void
    {
        $this->createDelivery('111', 'SIN ASIGNAR');

        $this->mock(SstDotacionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findAffiliateWithConvenios')
                ->once()
                ->with('CC', '111')
                ->andReturn($this->affiliate('111', 'HMFS - BELLO'));

            $mock->shouldNotReceive('getAffiliatesIndexedByDocument');
            $mock->shouldNotReceive('findAffiliate');
            $mock->shouldReceive('getInventoryItems')->andReturn([]);
        });

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['resolvedDocuments']);
        $this->assertSame(1, $stats['updatedRecords']);
    }

    public function test_backfill_leaves_records_that_already_have_a_hospital_untouched(): void
    {
        $assigned = $this->createDelivery('444', 'HOSPITAL CENTRAL', 'MEDICO GENERAL');

        $this->mockDotacionService([
            'CC-444' => $this->affiliate('444', 'OTRO HOSPITAL'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(0, $stats['pendingDocuments']);
        $this->assertSame('HOSPITAL CENTRAL', $assigned->fresh()->affiliate_hospital);
        $this->assertSame('MEDICO GENERAL', $assigned->fresh()->affiliate_role);
    }

    public function test_backfill_keeps_placeholder_when_affiliate_source_has_no_hospital(): void
    {
        $unresolved = $this->createDelivery('555', 'SIN ASIGNAR');

        $this->mockDotacionService([
            'CC-555' => $this->affiliate('555', 'SIN ASIGNAR', null),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['unresolvedDocuments']);
        $this->assertSame(0, $stats['updatedRecords']);
        $this->assertSame('SIN ASIGNAR', $unresolved->fresh()->affiliate_hospital);
    }

    public function test_backfill_dry_run_only_reads_the_database_and_never_calls_prosanet(): void
    {
        $record = $this->createDelivery('666', 'SIN ASIGNAR');
        $this->createReturn('666', 'HOSPITAL CENTRAL');
        $this->createDelivery('667', 'HOSPITAL CENTRAL', 'ENFERMERA');

        $this->mock(SstDotacionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('findAffiliateWithConvenios');
            $mock->shouldNotReceive('findAffiliate');
        });

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals([], null, true);

        $this->assertSame(1, $stats['pendingDocuments']);
        $this->assertSame(1, $stats['missingHospitalRecords']);
        $this->assertSame(2, $stats['missingRoleRecords']);
        $this->assertSame(0, $stats['processedDocuments']);
        $this->assertSame(0, $stats['updatedRecords']);
        $this->assertSame(0, $stats['updatedRoleRecords']);
        $this->assertSame('SIN ASIGNAR', $record->fresh()->affiliate_hospital);
        $this->assertNull($record->fresh()->affiliate_role);
    }

    public function test_backfill_assigns_role_alongside_hospital(): void
    {
        $delivery = $this->createDelivery('111', 'SIN ASIGNAR');
        $return = $this->createReturn('111', null, '  ');

        $this->mockDotacionService([
            'CC-111' => $this->affiliate('111', 'HMFS - BELLO', 'AUXILIAR DE ENFERMERIA'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['resolvedDocuments']);
        $this->assertSame(2, $stats['updatedRecords']);
        $this->assertSame(2, $stats['updatedRoleRecords']);
        $this->assertSame('AUXILIAR DE ENFERMERIA', $delivery->fresh()->affiliate_role);
        $this->assertSame('AUXILIAR DE ENFERMERIA', $return->fresh()->affiliate_role);
        $this->assertSame('HMFS - BELLO', $return->fresh()->affiliate_hospital);
    }

    public function test_backfill_fills_only_the_role_when_hospital_is_already_assigned(): void
    {
        $record = $this->createDelivery('222', 'HOSPITAL CENTRAL');

        $this->mockDotacionService([
            'CC-222' => $this->affiliate('222', 'OTRO HOSPITAL', 'BACTERIOLOGO(A)'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['pendingDocuments']);
        $this->assertSame(0, $stats['updatedRecords']);
        $this->assertSame(1, $stats['updatedRoleRecords']);
        $this->assertSame('HOSPITAL CENTRAL', $record->fresh()->affiliate_hospital);
        $this->assertSame('BACTERIOLOGO(A)', $record->fresh()->affiliate_role);
    }

    public function test_backfill_never_overwrites_an_existing_role(): void
    {
        $record = $this->createDelivery('333', 'SIN ASIGNAR', 'MEDICO GENERAL');

        $this->mockDotacionService([
            'CC-333' => $this->affiliate('333', 'HMFS - BELLO', 'ENFERMERA'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['updatedRecords']);
        $this->assertSame(0, $stats['updatedRoleRecords']);
        $this->assertSame('MEDICO GENERAL', $record->fresh()->affiliate_role);
    }

    public function test_backfill_waits_for_the_rate_limit_instead_of_exceeding_it(): void
    {
        config(['convenios.prosanet_per_minute' => 2]);
        RateLimiter::clear('convenio-prosanet-lookup');
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => RateLimiter::clear('convenio-prosanet-lookup'));

        foreach (['101', '102', '103', '104', '105'] as $number) {
            $this->createDelivery($number, 'SIN ASIGNAR');
        }

        $this->mockDotacionService(array_combine(
            ['CC-101', 'CC-102', 'CC-103', 'CC-104', 'CC-105'],
            array_map(fn (string $n) => $this->affiliate($n, 'HMFS - BELLO'), ['101', '102', '103', '104', '105']),
        ));

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals([], null, false, true);

        $this->assertSame(5, $stats['processedDocuments']);
        $this->assertSame(5, $stats['updatedRecords']);
        $this->assertFalse($stats['rateLimited']);
        Sleep::assertSleptTimes(2);
    }

    public function test_backfill_without_waiting_stops_when_the_rate_limit_is_exhausted(): void
    {
        config(['convenios.prosanet_per_minute' => 2]);
        RateLimiter::clear('convenio-prosanet-lookup');
        Sleep::fake();

        foreach (['101', '102', '103'] as $number) {
            $this->createDelivery($number, 'SIN ASIGNAR');
        }

        $this->mockDotacionService([
            'CC-101' => $this->affiliate('101', 'HMFS - BELLO'),
            'CC-102' => $this->affiliate('102', 'HMFS - BELLO'),
            'CC-103' => $this->affiliate('103', 'HMFS - BELLO'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(2, $stats['processedDocuments']);
        $this->assertSame(0, $stats['unresolvedDocuments']);
        $this->assertTrue($stats['rateLimited']);
        Sleep::assertNeverSlept();
    }

    public function test_report_includes_recovered_record_when_filtering_by_that_hospital(): void
    {
        $recovered = $this->createDelivery('777', 'SIN ASIGNAR');
        $this->createDelivery('888', 'CLÍNICA NORTE');

        $this->mockDotacionService([
            'CC-777' => $this->affiliate('777', 'HMFS - BELLO'),
            'CC-888' => $this->affiliate('888', 'CLÍNICA NORTE'),
        ]);

        $path = app(SstDeliveryReportService::class)->generateReport(['hospitals' => ['HMFS - BELLO']]);

        $this->assertSame('HMFS - BELLO', $recovered->fresh()->affiliate_hospital);

        $rows = $this->readSheetRows($path, 'Entregas');

        $documentNumbers = array_column($rows, 4);
        $this->assertContains('777', $documentNumbers);
        $this->assertNotContains('888', $documentNumbers);
    }

    public function test_report_filters_by_multiple_hospitals(): void
    {
        $this->createDelivery('111', 'HOSPITAL CENTRAL');
        $this->createDelivery('222', 'CLÍNICA NORTE');
        $this->createDelivery('333', 'HOSPITAL SUR');

        $this->mockDotacionService([]);

        $path = app(SstDeliveryReportService::class)->generateReport([
            'hospitals' => ['HOSPITAL CENTRAL', 'HOSPITAL SUR'],
        ]);

        $documentNumbers = array_column($this->readSheetRows($path, 'Entregas'), 4);

        $this->assertContains('111', $documentNumbers);
        $this->assertContains('333', $documentNumbers);
        $this->assertNotContains('222', $documentNumbers);
    }

    public function test_report_without_hospital_filter_includes_every_record(): void
    {
        $this->createDelivery('111', 'HOSPITAL CENTRAL');
        $this->createDelivery('222', 'CLÍNICA NORTE');

        $this->mockDotacionService([]);

        $path = app(SstDeliveryReportService::class)->generateReport([]);

        $documentNumbers = array_column($this->readSheetRows($path, 'Entregas'), 4);

        $this->assertContains('111', $documentNumbers);
        $this->assertContains('222', $documentNumbers);
    }

    /**
     * @return array<int, array<int, mixed>> Data rows (header excluded).
     */
    private function readSheetRows(string $path, string $sheetName): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getSheetByName($sheetName)->toArray();
        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        return array_slice($rows, 1);
    }
}
