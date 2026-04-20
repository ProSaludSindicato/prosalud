<?php

namespace Tests\Feature;

use App\Models\VotingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DelegadosBySedeJsonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function putDelegadosXlsxOnDisk(array $rows): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');
        $path = storage_path('framework/testing/delegados-by-sede-'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);
        Storage::disk('prosalud-private')->put('data/DELEGADOS.xlsx', $content);
    }

    public function test_by_sede_returns_delegados_as_json_array_when_filtered_rows_have_non_sequential_keys(): void
    {
        Storage::fake('prosalud-private');
        VotingSetting::current()->update(['active_mode' => 'candidate']);

        $this->putDelegadosXlsxOnDisk([
            ['NOMBRE Y APELLIDOS', 'CEDULA', 'SEDE', 'PROCESO', 'NOTA'],
            ['A Uno', '1111111111', 'Bello', 'P1', ''],
            ['B Dos', '2222222222', 'Medellin', 'P1', ''],
            ['C Tres', '3333333333', 'Bello', 'P1', ''],
        ]);

        $response = $this->getJson('/api/delegados/by-sede?sede=Bello');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $delegados = $response->json('delegados');
        $this->assertIsArray($delegados);
        $this->assertCount(2, $delegados);
        $this->assertCount(2, array_values($delegados));
    }
}
