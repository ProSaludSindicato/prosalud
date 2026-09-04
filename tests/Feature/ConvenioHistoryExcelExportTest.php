<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioHistoryExcelExportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ConvenioHistoryExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private function apiCookieForUser(User $user): string
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function exportAs(User $user, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/convenios-manual/export/excel',
            $payload,
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );
    }

    public function test_export_requires_authentication(): void
    {
        $response = $this->call(
            'POST',
            '/api/convenios-manual/export/excel',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertUnauthorized();
    }

    public function test_export_requires_view_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();

        $this->exportAs($user)->assertForbidden();
    }

    public function test_export_rejects_invalid_date_range(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $this->exportAs($user, [
            'fecha_desde' => '2026-09-10',
            'fecha_hasta' => '2026-09-01',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_export_excel_includes_unsigned_affiliates_and_autofilter(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $pending = ConvenioEmailTracking::factory()->create([
            'documento' => '1007223190',
            'nombre_afiliado' => 'Ana Pérez',
            'email_afiliado' => 'ana@example.com',
            'nombre_convenio' => 'Hospital La María',
            'sede' => 'E.S.E. Hospital La Maria - Medellín (Ant)',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
            'convenio_data' => [
                'proceso' => 'AUXILIAR DE ENFERMERIA',
                'fecha_inicio' => '2026-01-01',
            ],
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '1007223191',
            'nombre_afiliado' => 'Luis Gómez',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '1007223192',
            'nombre_afiliado' => 'Test User',
            'estado' => 'verificacion',
            'is_test' => true,
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->exportAs($user, [
            'estado_filtro' => 'firma_pendiente_firma',
            'is_test' => 0,
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );

        $spreadsheet = IOFactory::load($response->getFile()->getPathname());

        $this->assertNotNull($spreadsheet->getSheetByName('Resumen'));
        $this->assertNotNull($spreadsheet->getSheetByName('Detalle Convenios'));
        $this->assertNotNull($spreadsheet->getSheetByName('Pendientes de firma'));

        $detail = $spreadsheet->getSheetByName('Detalle Convenios');
        $this->assertNotFalse($detail->getAutoFilter()->getRange());
        $this->assertNotSame('', $detail->getAutoFilter()->getRange());
        $this->assertSame('A2', $detail->getFreezePane());

        $headers = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($detail->getHighestColumn());
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $headers[] = (string) $detail->getCell($colLetter.'1')->getValue();
        }

        $this->assertContains('Documento', $headers);
        $this->assertContains('Estado firma', $headers);
        $this->assertContains('Pendiente de firmar', $headers);
        $this->assertContains('Proceso', $headers);

        $pendingSheet = $spreadsheet->getSheetByName('Pendientes de firma');
        $this->assertNotSame('', $pendingSheet->getAutoFilter()->getRange());
        $this->assertSame('1007223190', (string) $pendingSheet->getCell('A2')->getValue());
        $this->assertSame('Sí', (string) $pendingSheet->getCell('H2')->getValue());
        $this->assertSame('', (string) $pendingSheet->getCell('A3')->getValue());

        $this->assertSame($pending->documento, (string) $detail->getCell('A2')->getValue());
        $this->assertSame('AUXILIAR DE ENFERMERIA', (string) $detail->getCell('Q2')->getValue());
    }

    public function test_export_sede_filter_matches_partial_hospital_name(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'documento' => '111',
            'sede' => 'E.S.E. Hospital La Maria - Medellín (Ant)',
            'nombre_convenio' => 'La María',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '222',
            'sede' => 'Hospital San Vicente',
            'nombre_convenio' => 'San Vicente',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $service = app(ConvenioHistoryExcelExportService::class);
        $path = $service->generateReport(['sede' => 'La Maria']);

        try {
            $spreadsheet = IOFactory::load($path);
            $detail = $spreadsheet->getSheetByName('Detalle Convenios');
            $this->assertSame('111', (string) $detail->getCell('A2')->getValue());
            $this->assertSame('', (string) $detail->getCell('A3')->getValue());
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_summary_counts_pending_signatures_by_sede(): void
    {
        ConvenioEmailTracking::factory()->create([
            'sede' => 'BELLO',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);
        ConvenioEmailTracking::factory()->create([
            'sede' => 'BELLO',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);
        ConvenioEmailTracking::factory()->create([
            'sede' => 'COPACABANA',
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $service = app(ConvenioHistoryExcelExportService::class);
        $path = $service->generateReport([]);

        try {
            $spreadsheet = IOFactory::load($path);
            $summary = $spreadsheet->getSheetByName('Resumen');
            $this->assertSame('REPORTE DE CONVENIOS PROSALUD', (string) $summary->getCell('A1')->getValue());
            $this->assertNotSame('', $summary->getAutoFilter()->getRange());

            $foundBello = false;
            $highestRow = (int) $summary->getHighestRow();
            for ($row = 1; $row <= $highestRow; $row++) {
                if ((string) $summary->getCell("A{$row}")->getValue() === 'BELLO') {
                    $this->assertSame(2, (int) $summary->getCell("B{$row}")->getValue());
                    $this->assertSame(1, (int) $summary->getCell("C{$row}")->getValue());
                    $foundBello = true;
                    break;
                }
            }
            $this->assertTrue($foundBello);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
