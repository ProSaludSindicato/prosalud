<?php

namespace Tests\Unit;

use App\Support\ConvenioSigningAuditLog;
use PHPUnit\Framework\TestCase;

class ConvenioSigningAuditLogTest extends TestCase
{
    public function test_presents_human_readable_labels_and_details(): void
    {
        $presented = ConvenioSigningAuditLog::present([
            'sessionId' => 'session-1',
            'startedAt' => '2026-09-02T15:00:00+00:00',
            'events' => [
                [
                    'id' => 'evt-1',
                    'type' => 'document_opened',
                    'timestamp' => '2026-09-02T15:00:01+00:00',
                ],
                [
                    'id' => 'evt-2',
                    'type' => 'page_navigated',
                    'timestamp' => '2026-09-02T15:00:02+00:00',
                    'metadata' => ['page' => 2, 'reason' => 'signature_page'],
                ],
                [
                    'id' => 'evt-3',
                    'type' => 'signature_drawn',
                    'timestamp' => '2026-09-02T15:00:03+00:00',
                ],
            ],
            'summary' => [
                'documentName' => 'convenio.pdf',
                'totalPages' => 2,
                'signaturePage' => null,
                'signatureMethod' => null,
                'submittedAt' => null,
                'downloadedAt' => null,
            ],
        ]);

        $this->assertSame('Documento abierto', $presented['events'][0]['label']);
        $this->assertSame('Llegó a la página de firma', $presented['events'][1]['label']);
        $this->assertSame('Página 2', $presented['events'][1]['detail']);
        $this->assertSame('Dibujó su firma', $presented['events'][2]['label']);
        $this->assertSame('draw', $presented['summary']['signatureMethod']);
        $this->assertSame('Dibujada', $presented['summary']['signatureMethodLabel']);
        $this->assertSame(2, $presented['summary']['signaturePage']);
    }

    public function test_unknown_event_types_are_not_shown_as_raw_technical_keys(): void
    {
        $this->assertSame('Actividad en el visor', ConvenioSigningAuditLog::eventLabel('document_opened_v2'));
    }
}
