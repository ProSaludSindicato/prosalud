<?php

namespace Tests\Feature;

use App\Services\DocxToPdfCloudConvertService;
use App\Services\DocxToPdfService;
use App\Services\WordToPdfApiService;
use Exception;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DocxToPdfServiceTest extends TestCase
{
    private string $docxPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wordtopdf.enabled' => true]);

        $this->docxPath = storage_path('app/tmp/orchestrator_test.docx');
        file_put_contents($this->docxPath, 'fake-docx');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->docxPath)) {
            @unlink($this->docxPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_uses_primary_converter_when_available_and_successful(): void
    {
        /** @var WordToPdfApiService&MockInterface $primary */
        $primary = Mockery::mock(WordToPdfApiService::class);
        $primary->shouldReceive('isAvailable')->once()->andReturn(true);
        $primary->shouldReceive('convert')
            ->once()
            ->with($this->docxPath, true)
            ->andReturn(['path' => 'tmp/test.pdf', 'content' => null, 'size' => 1024]);

        /** @var DocxToPdfCloudConvertService&MockInterface $fallback */
        $fallback = Mockery::mock(DocxToPdfCloudConvertService::class);
        $fallback->shouldNotReceive('convert');

        $service = new DocxToPdfService($primary, $fallback);
        $result = $service->convert($this->docxPath);

        $this->assertSame('tmp/test.pdf', $result['path']);
        $this->assertSame(1024, $result['size']);
    }

    public function test_falls_back_to_cloudconvert_when_primary_fails(): void
    {
        /** @var WordToPdfApiService&MockInterface $primary */
        $primary = Mockery::mock(WordToPdfApiService::class);
        $primary->shouldReceive('isAvailable')->once()->andReturn(true);
        $primary->shouldReceive('convert')
            ->once()
            ->with($this->docxPath, true)
            ->andThrow(new Exception('Primary service timeout'));

        /** @var DocxToPdfCloudConvertService&MockInterface $fallback */
        $fallback = Mockery::mock(DocxToPdfCloudConvertService::class);
        $fallback->shouldReceive('isAvailable')->once()->andReturn(true);
        $fallback->shouldReceive('convert')
            ->once()
            ->with($this->docxPath, true)
            ->andReturn(['path' => 'tmp/fallback.pdf', 'content' => null, 'size' => 2048]);

        $service = new DocxToPdfService($primary, $fallback);
        $result = $service->convert($this->docxPath);

        $this->assertSame('tmp/fallback.pdf', $result['path']);
        $this->assertSame(2048, $result['size']);
    }

    public function test_uses_cloudconvert_directly_when_primary_disabled(): void
    {
        config(['wordtopdf.enabled' => false]);

        /** @var WordToPdfApiService&MockInterface $primary */
        $primary = Mockery::mock(WordToPdfApiService::class);
        $primary->shouldNotReceive('isAvailable');
        $primary->shouldNotReceive('convert');

        /** @var DocxToPdfCloudConvertService&MockInterface $fallback */
        $fallback = Mockery::mock(DocxToPdfCloudConvertService::class);
        $fallback->shouldReceive('isAvailable')->once()->andReturn(true);
        $fallback->shouldReceive('convert')
            ->once()
            ->with($this->docxPath, true)
            ->andReturn(['path' => 'tmp/direct.pdf', 'content' => null, 'size' => 512]);

        $service = new DocxToPdfService($primary, $fallback);
        $result = $service->convert($this->docxPath);

        $this->assertSame('tmp/direct.pdf', $result['path']);
    }

    public function test_throws_when_both_converters_fail(): void
    {
        /** @var WordToPdfApiService&MockInterface $primary */
        $primary = Mockery::mock(WordToPdfApiService::class);
        $primary->shouldReceive('isAvailable')->once()->andReturn(true);
        $primary->shouldReceive('convert')
            ->once()
            ->andThrow(new Exception('Primary failed'));

        /** @var DocxToPdfCloudConvertService&MockInterface $fallback */
        $fallback = Mockery::mock(DocxToPdfCloudConvertService::class);
        $fallback->shouldReceive('isAvailable')->once()->andReturn(true);
        $fallback->shouldReceive('convert')
            ->once()
            ->andThrow(new Exception('Fallback failed'));

        $service = new DocxToPdfService($primary, $fallback);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('todos los servicios fallaron');

        $service->convert($this->docxPath);
    }

    public function test_is_available_returns_true_when_either_converter_available(): void
    {
        /** @var WordToPdfApiService&MockInterface $primary */
        $primary = Mockery::mock(WordToPdfApiService::class);
        $primary->shouldReceive('isAvailable')->once()->andReturn(false);

        /** @var DocxToPdfCloudConvertService&MockInterface $fallback */
        $fallback = Mockery::mock(DocxToPdfCloudConvertService::class);
        $fallback->shouldReceive('isAvailable')->once()->andReturn(true);

        $service = new DocxToPdfService($primary, $fallback);

        $this->assertTrue($service->isAvailable());
    }

    public function test_cloudconvert_instantiates_without_throwing_when_api_key_missing(): void
    {
        config(['cloudconvert.api_key' => '']);

        $service = new DocxToPdfCloudConvertService;

        $this->assertFalse($service->isAvailable());
    }

    public function test_cloudconvert_is_available_when_api_key_configured(): void
    {
        config(['cloudconvert.api_key' => 'test-api-key-123']);

        $service = new DocxToPdfCloudConvertService;

        $this->assertTrue($service->isAvailable());
    }
}
