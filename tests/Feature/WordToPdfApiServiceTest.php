<?php

namespace Tests\Feature;

use App\Services\WordToPdfApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordToPdfApiServiceTest extends TestCase
{
    private string $docxPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wordtopdf.enabled' => true,
            'wordtopdf.base_url' => 'http://wordtopdf.test',
            'wordtopdf.api_key' => 'test-api-key',
            'wordtopdf.timeout' => 30,
            'wordtopdf.connect_timeout' => 5,
            'wordtopdf.protect_pdf' => true,
            'wordtopdf.verify_ssl' => false,
            'wordtopdf.max_file_size' => 20 * 1024 * 1024,
            'wordtopdf.temp_storage_path' => storage_path('app/tmp'),
        ]);

        $tempDir = storage_path('app/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $this->docxPath = $tempDir.'/test_certificado.docx';
        file_put_contents($this->docxPath, 'fake-docx-content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->docxPath)) {
            @unlink($this->docxPath);
        }

        $pdfPath = storage_path('app/tmp/test_certificado.pdf');
        if (file_exists($pdfPath)) {
            @unlink($pdfPath);
        }

        parent::tearDown();
    }

    public function test_convert_successfully_returns_pdf_and_saves_to_storage(): void
    {
        Http::fake([
            'http://wordtopdf.test/api/convert' => Http::response('%PDF-1.4 fake pdf content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $service = new WordToPdfApiService;
        $result = $service->convert($this->docxPath, true);

        $this->assertArrayHasKey('path', $result);
        $this->assertSame('tmp/test_certificado.pdf', $result['path']);
        $this->assertNull($result['content']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertFileExists(storage_path('app/'.$result['path']));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://wordtopdf.test/api/convert'
                && $request->hasHeader('Authorization', 'Bearer test-api-key');
        });
    }

    public function test_convert_successfully_returns_binary_content(): void
    {
        Http::fake([
            'http://wordtopdf.test/api/convert' => Http::response('%PDF-1.4 binary content', 200),
        ]);

        $service = new WordToPdfApiService;
        $result = $service->convert($this->docxPath, false);

        $this->assertArrayHasKey('content', $result);
        $this->assertStringStartsWith('%PDF', $result['content']);
        $this->assertGreaterThan(0, $result['size']);
    }

    public function test_convert_throws_on_http_error(): void
    {
        Http::fake([
            'http://wordtopdf.test/api/convert' => Http::response([
                'success' => false,
                'error' => 'Error al convertir el documento a PDF.',
            ], 422),
        ]);

        $service = new WordToPdfApiService;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error al convertir DOCX a PDF via WordToPdf API');

        $service->convert($this->docxPath);
    }

    public function test_convert_throws_when_response_is_not_pdf(): void
    {
        Http::fake([
            'http://wordtopdf.test/api/convert' => Http::response('not a pdf', 200),
        ]);

        $service = new WordToPdfApiService;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no es un PDF válido');

        $service->convert($this->docxPath);
    }

    public function test_is_available_returns_true_when_health_is_ok(): void
    {
        Http::fake([
            'http://wordtopdf.test/health' => Http::response([
                'status' => 'ok',
                'libreoffice' => true,
            ]),
        ]);

        $service = new WordToPdfApiService;

        $this->assertTrue($service->isAvailable());
    }

    public function test_is_available_returns_false_when_health_is_degraded(): void
    {
        Http::fake([
            'http://wordtopdf.test/health' => Http::response([
                'status' => 'degraded',
                'libreoffice' => false,
            ]),
        ]);

        $service = new WordToPdfApiService;

        $this->assertFalse($service->isAvailable());
    }

    public function test_is_available_caches_result_and_avoids_repeated_http_calls(): void
    {
        Http::fake([
            'http://wordtopdf.test/health' => Http::response([
                'status' => 'ok',
                'libreoffice' => true,
            ]),
        ]);

        $service = new WordToPdfApiService;

        $this->assertTrue($service->isAvailable());
        $this->assertTrue($service->isAvailable());
        $this->assertTrue($service->isAvailable());

        Http::assertSentCount(1);
    }
}
