<?php

namespace Tests\Feature;

use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class BackfillSstDeliveryHospitalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createDelivery(string $documentNumber, ?string $hospital, int $itemCount): SstDeliveryRecord
    {
        $delivery = SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-'.$documentNumber,
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => $documentNumber,
            'affiliate_first_name' => 'JUAN',
            'affiliate_last_name' => 'PEREZ',
            'affiliate_hospital' => $hospital,
            'affiliate_role' => 'ENFERMERA',
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => $documentNumber,
            'delivery_type' => 'first_time',
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            SstDeliveryItem::query()->create([
                'id' => (string) Str::uuid(),
                'delivery_id' => $delivery->id,
                'item_id' => 'item-'.$i,
                'item_name' => 'Gorro',
                'item_category' => 'EPP',
                'quantity' => 1,
            ]);
        }

        return $delivery;
    }

    private function mockProsanet(int $expectedLookups): void
    {
        $this->mock(SstDotacionService::class, function (MockInterface $mock) use ($expectedLookups) {
            $mock->shouldReceive('findAffiliateWithConvenios')
                ->times($expectedLookups)
                ->andReturn([
                    'id' => 'CC-111',
                    'firstName' => 'JUAN',
                    'lastName' => 'PEREZ',
                    'documentType' => 'CC',
                    'documentNumber' => '111',
                    'hospital' => 'HMFS - BELLO',
                    'role' => 'ENFERMERA',
                ]);
            $mock->shouldReceive('getInventoryItems')->andReturn([]);
        });
    }

    public function test_dry_run_reports_item_lines_without_calling_prosanet(): void
    {
        $this->createDelivery('111', 'SIN ASIGNAR', 3);
        $this->createDelivery('222', null, 2);
        $this->createDelivery('333', 'HOSPITAL CENTRAL', 5);
        $this->mockProsanet(0);

        $this->artisan('dotacion-epp:backfill-hospitals', ['--dry-run' => true])
            ->expectsTable(['Métrica', 'Valor'], [
                ['Afiliados con datos faltantes', 2],
                ['Registros sin hospital', 2],
                ['Líneas de artículos sin hospital (filas del Excel)', 5],
                ['Registros sin cargo/proceso', 0],
                ['Consultas a ProSaNet necesarias', 2],
            ])
            ->assertSuccessful();
    }

    public function test_without_force_a_non_interactive_run_is_cancelled(): void
    {
        $record = $this->createDelivery('111', 'SIN ASIGNAR', 1);
        $this->mockProsanet(0);

        $this->artisan('dotacion-epp:backfill-hospitals', ['--no-interaction' => true])
            ->expectsOutputToContain('Usa --force')
            ->assertSuccessful();

        $this->assertSame('SIN ASIGNAR', $record->fresh()->affiliate_hospital);
    }

    public function test_force_runs_without_asking_for_confirmation(): void
    {
        $record = $this->createDelivery('111', 'SIN ASIGNAR', 1);
        $this->mockProsanet(1);

        $this->artisan('dotacion-epp:backfill-hospitals', ['--force' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame('HMFS - BELLO', $record->fresh()->affiliate_hospital);
    }

    public function test_interactive_run_updates_records_when_confirmed(): void
    {
        $record = $this->createDelivery('111', 'SIN ASIGNAR', 1);
        $this->mockProsanet(1);

        $this->artisan('dotacion-epp:backfill-hospitals')
            ->expectsConfirmation('¿Continuar?', 'yes')
            ->assertSuccessful();

        $this->assertSame('HMFS - BELLO', $record->fresh()->affiliate_hospital);
    }

    public function test_interactive_run_changes_nothing_when_declined(): void
    {
        $record = $this->createDelivery('111', 'SIN ASIGNAR', 1);
        $this->mockProsanet(0);

        $this->artisan('dotacion-epp:backfill-hospitals')
            ->expectsConfirmation('¿Continuar?', 'no')
            ->assertSuccessful();

        $this->assertSame('SIN ASIGNAR', $record->fresh()->affiliate_hospital);
    }

    public function test_nothing_pending_finishes_without_asking_for_confirmation(): void
    {
        $this->createDelivery('111', 'HOSPITAL CENTRAL', 1);
        $this->mockProsanet(0);

        $this->artisan('dotacion-epp:backfill-hospitals')
            ->expectsOutputToContain('No hay entregas ni devoluciones')
            ->assertSuccessful();
    }
}
