<?php

namespace Tests\Unit;

use App\Constants\RequestTypes;
use App\Services\RequestFileNamingService;
use Tests\TestCase;

class RequestFileNamingServiceTest extends TestCase
{
    private RequestFileNamingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RequestFileNamingService;
    }

    public function test_get_display_filename_uses_field_label(): void
    {
        $filename = $this->service->getDisplayFilename('anexoEvidenciaSolicitud', 'jpg');

        $this->assertSame('Evidencia que respalda la solicitud.jpg', $filename);
    }

    public function test_get_display_filename_includes_request_type_and_document_number(): void
    {
        $filename = $this->service->getDisplayFilename('anexoFormatoDiligenciado', 'pdf', [
            'request_type' => RequestTypes::COMPENSACION_ANUAL,
            'document_number' => '1098765432',
        ]);

        $this->assertSame(
            'Compensación anual diferida - 1098765432 - Formato diligenciado.pdf',
            $filename,
        );
    }

    public function test_get_display_filename_falls_back_to_readable_key(): void
    {
        $filename = $this->service->getDisplayFilename('nuevoCampoAdjunto', 'pdf');

        $this->assertSame('Nuevo Campo Adjunto.pdf', $filename);
    }

    public function test_get_storage_filename_uses_short_name_and_extension(): void
    {
        $filename = $this->service->getStorageFilename('anexoEvidenciaSolicitud', 'jpg');

        $this->assertMatchesRegularExpression('/^EvidenciaSolicitud-[a-f0-9]{6}\.jpg$/', $filename);
    }

    public function test_get_storage_filename_includes_request_type_and_document_number(): void
    {
        $filename = $this->service->getStorageFilename('anexoFormatoDiligenciado', 'pdf', [
            'request_type' => RequestTypes::COMPENSACION_ANUAL,
            'document_number' => '1098765432',
        ]);

        $this->assertMatchesRegularExpression(
            '/^CompAnual-1098765432-FormatoDiligenciado-[a-f0-9]{6}\.pdf$/',
            $filename,
        );
    }

    public function test_get_field_label_returns_mapped_label(): void
    {
        $this->assertSame('Certificación bancaria', $this->service->getFieldLabel('certificacionBancaria'));
        $this->assertSame('Anexo descanso laboral', $this->service->getFieldLabel('anexoDescanso'));
    }
}
