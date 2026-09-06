<?php

namespace Tests\Feature;

use App\Exceptions\AutoSignApiException;
use App\Services\AutoSignApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoSignApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAutoSign();
    }

    public function test_sign_returns_pdf_and_sends_api_key_header(): void
    {
        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response('%PDF-1.4 signed', 200, [
                'Content-Type' => 'application/pdf',
                'X-Signature-Detection-Method' => 'graphic_line',
                'X-Signature-Page' => '2',
                'X-Duration-Ms' => '123',
            ]),
        ]);

        $result = (new AutoSignApiService)->sign('%PDF-1.4 original', 'convenio.pdf', '42');

        $this->assertSame('%PDF-1.4 signed', $result->pdfContents);
        $this->assertSame('graphic_line', $result->detectionMethod);
        $this->assertSame(2, $result->page);
        $this->assertSame(123, $result->durationMs);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return $request->url() === 'https://auto-sign.test/api/auto-sign'
                && $request->hasHeader('X-Api-Key', 'test-auto-sign-key')
                && $request->hasHeader('X-Reference-Id', '42')
                && str_contains($body, 'JORGE IVAN')
                && str_contains($body, 'PRESIDENTE')
                && str_contains($body, 'search_text')
                && str_contains($body, 'search_page');
        });
    }

    public function test_sign_throws_on_unauthorized(): void
    {
        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response([
                'error' => 'No autorizado',
                'code' => 'unauthorized',
            ], 401),
        ]);

        try {
            (new AutoSignApiService)->sign('%PDF-1.4 original', 'convenio.pdf', '1');
            $this->fail('Expected AutoSignApiException');
        } catch (AutoSignApiException $exception) {
            $this->assertSame('unauthorized', $exception->errorCode);
            $this->assertSame(401, $exception->httpStatus);
        }
    }

    public function test_sign_throws_when_anchor_is_not_found(): void
    {
        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response([
                'error' => 'No se encontró el ancla',
                'code' => 'anchor_not_found',
            ], 422),
        ]);

        try {
            (new AutoSignApiService)->sign('%PDF-1.4 original', 'convenio.pdf', '1');
            $this->fail('Expected AutoSignApiException');
        } catch (AutoSignApiException $exception) {
            $this->assertSame('anchor_not_found', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }
    }

    public function test_is_configured_is_false_without_api_key(): void
    {
        config(['services.auto_sign.api_key' => '']);

        $this->assertFalse((new AutoSignApiService)->isConfigured());
    }

    public function test_is_available_returns_true_when_health_is_ok(): void
    {
        Http::fake([
            'https://auto-sign.test/api/health' => Http::response([
                'status' => 'ok',
                'version' => '1.0.0',
            ]),
        ]);

        $this->assertTrue((new AutoSignApiService)->isAvailable());
    }

    private function configureAutoSign(): void
    {
        config([
            'services.auto_sign.url' => 'https://auto-sign.test',
            'services.auto_sign.api_key' => 'test-auto-sign-key',
            'services.auto_sign.timeout' => 30,
            'services.auto_sign.connect_timeout' => 5,
            'services.auto_sign.verify_ssl' => false,
            'convenio_signing.president.search_text' => 'JORGE IVAN ÁLVAREZ SOTO',
            'convenio_signing.president.secondary_anchor' => 'PRESIDENTE',
            'convenio_signing.president.search_page' => 2,
            'convenio_signing.president.width' => 48,
            'convenio_signing.president.height' => 63,
            'convenio_signing.president.offset_x' => 0,
            'convenio_signing.president.offset_y' => -14,
            'convenio_signing.president.signature_path' => 'resources/signatures/presidente.png',
        ]);
    }
}
