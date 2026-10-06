<?php

namespace Tests\Feature;

use App\Models\SstDeliveryRecord;
use App\Models\SstReturnRecord;
use App\Services\SstDeliveryReportService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $mock->shouldReceive('findAffiliate')
                ->andReturnUsing(fn (string $type, string $number) => $affiliatesByDocument[$type.'-'.$number] ?? null);

            $mock->shouldReceive('getInventoryItems')->andReturn([]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function affiliate(string $documentNumber, string $hospital): array
    {
        return [
            'id' => 'CC-'.$documentNumber,
            'firstName' => 'JUAN',
            'lastName' => 'PEREZ',
            'documentType' => 'CC',
            'documentNumber' => $documentNumber,
            'hospital' => $hospital,
            'role' => 'BACTERIOLOGO(A)',
            'active' => true,
            'status' => 'ACTIVO',
        ];
    }

    private function createDelivery(string $documentNumber, ?string $hospital): SstDeliveryRecord
    {
        return SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-'.$documentNumber,
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => $documentNumber,
            'affiliate_first_name' => 'JUAN',
            'affiliate_last_name' => 'PEREZ',
            'affiliate_hospital' => $hospital,
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => $documentNumber,
            'delivery_type' => 'first_time',
        ]);
    }

    private function createReturn(string $documentNumber, ?string $hospital): SstReturnRecord
    {
        return SstReturnRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-'.$documentNumber,
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => $documentNumber,
            'affiliate_first_name' => 'JUAN',
            'affiliate_last_name' => 'PEREZ',
            'affiliate_hospital' => $hospital,
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

    public function test_backfill_leaves_records_that_already_have_a_hospital_untouched(): void
    {
        $assigned = $this->createDelivery('444', 'HOSPITAL CENTRAL');

        $this->mockDotacionService([
            'CC-444' => $this->affiliate('444', 'OTRO HOSPITAL'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(0, $stats['pendingDocuments']);
        $this->assertSame('HOSPITAL CENTRAL', $assigned->fresh()->affiliate_hospital);
    }

    public function test_backfill_keeps_placeholder_when_affiliate_source_has_no_hospital(): void
    {
        $unresolved = $this->createDelivery('555', 'SIN ASIGNAR');

        $this->mockDotacionService([
            'CC-555' => $this->affiliate('555', 'SIN ASIGNAR'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals();

        $this->assertSame(1, $stats['unresolvedDocuments']);
        $this->assertSame(0, $stats['updatedRecords']);
        $this->assertSame('SIN ASIGNAR', $unresolved->fresh()->affiliate_hospital);
    }

    public function test_backfill_dry_run_resolves_without_persisting(): void
    {
        $record = $this->createDelivery('666', 'SIN ASIGNAR');

        $this->mockDotacionService([
            'CC-666' => $this->affiliate('666', 'HMFS - BELLO'),
        ]);

        $stats = app(SstDeliveryReportService::class)->backfillMissingHospitals([], null, true);

        $this->assertSame(1, $stats['resolvedDocuments']);
        $this->assertSame(0, $stats['updatedRecords']);
        $this->assertSame('SIN ASIGNAR', $record->fresh()->affiliate_hospital);
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
