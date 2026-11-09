<?php

namespace Tests\Feature;

use App\Jobs\GenerateSstDeliveryReportJob;
use App\Models\ApiToken;
use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Models\User;
use App\Services\SstDeliveryReportService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SstDeliveryReportSignaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        Storage::fake('local');

        $this->mock(SstDotacionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getInventoryItems')->andReturn([]);
        });
    }

    private function signatureJpeg(int $width = 1710, int $height = 260): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 10, $width - 10, $height - 10, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagejpeg($image, null, 90);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    private function createDelivery(?string $signaturePath, int $itemsCount): SstDeliveryRecord
    {
        $record = SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-123456',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '123456',
            'affiliate_full_name' => 'JUAN PEREZ',
            'affiliate_hospital' => 'Hospital Central',
            'affiliate_role' => 'ENFERMERO(A)',
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => '2026-03-15 10:00:00',
            'signed_document_type' => 'CC',
            'signed_document_number' => '123456',
            'delivery_type' => 'periodic',
            'signature_path' => $signaturePath,
        ]);

        for ($i = 0; $i < $itemsCount; $i++) {
            SstDeliveryItem::query()->create([
                'id' => (string) Str::uuid(),
                'delivery_id' => $record->id,
                'item_id' => 'item-'.$i,
                'item_name' => 'Artículo '.$i,
                'item_category' => 'EPP',
                'quantity' => 1,
            ]);
        }

        return $record;
    }

    /**
     * @return array<int, string>
     */
    private function mediaFiles(string $xlsxPath): array
    {
        $zip = new \ZipArchive;
        $zip->open($xlsxPath);

        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/media/')) {
                $media[] = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $media;
    }

    /**
     * @return array<string, mixed>
     */
    private function signatureOptions(): array
    {
        return [
            'includeSignatures' => true,
            'signatureSize' => ['width' => 100, 'height' => 50],
        ];
    }

    public function test_signature_is_stored_once_and_downscaled_for_deliveries_with_many_items(): void
    {
        Storage::disk('prosalud-private')->put('dotacion-signatures/a.jpg', $this->signatureJpeg());
        Storage::disk('prosalud-private')->put('dotacion-signatures/b.jpg', $this->signatureJpeg());
        $this->createDelivery('dotacion-signatures/a.jpg', 5);
        $this->createDelivery('dotacion-signatures/b.jpg', 3);

        $path = app(SstDeliveryReportService::class)->generateReport(
            ['startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
            $this->signatureOptions()
        );

        $media = $this->mediaFiles($path);
        $this->assertCount(2, $media);

        foreach ($media as $image) {
            [$width, $height] = getimagesizefromstring($image);
            $this->assertLessThanOrEqual(300, $width);
            $this->assertLessThanOrEqual(150, $height);
        }

        $sheet = IOFactory::load($path)->getSheetByName('Entregas');
        $this->assertCount(8, $sheet->getDrawingCollection());

        @unlink($path);
    }

    public function test_missing_signature_file_does_not_break_report(): void
    {
        $this->createDelivery('dotacion-signatures/missing.jpg', 2);

        $path = app(SstDeliveryReportService::class)->generateReport([], $this->signatureOptions());

        $this->assertFileExists($path);
        $this->assertCount(0, $this->mediaFiles($path));
        $this->assertSame('Artículo 0', IOFactory::load($path)->getSheetByName('Entregas')->getCell('K2')->getValue());

        @unlink($path);
    }

    public function test_report_without_signatures_flag_skips_images(): void
    {
        Storage::disk('prosalud-private')->put('dotacion-signatures/a.jpg', $this->signatureJpeg());
        $this->createDelivery('dotacion-signatures/a.jpg', 2);

        $path = app(SstDeliveryReportService::class)->generateReport([], []);

        $this->assertCount(0, $this->mediaFiles($path));

        @unlink($path);
    }

    public function test_excel_endpoint_dispatches_queued_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Permission::findOrCreate('dotacion.view');
        $user->givePermissionTo('dotacion.view');

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $plainToken)
            ->get('/api/dotacion-epp/reports/deliveries/excel?startDate=2026-01-01&endDate=2026-12-31&includeSignatures=true', [
                'Accept' => 'application/json',
            ]);

        $response->assertAccepted();
        $jobId = $response->json('job_id');

        Queue::assertPushed(GenerateSstDeliveryReportJob::class, function (GenerateSstDeliveryReportJob $job) use ($jobId) {
            return $job->jobId === $jobId
                && $job->options['includeSignatures'] === true
                && $job->filters['startDate'] === '2026-01-01';
        });
        $this->assertSame('processing', cache()->get(GenerateSstDeliveryReportJob::cacheKey($jobId))['status']);
    }

    public function test_job_stores_report_and_marks_it_completed(): void
    {
        Storage::disk('prosalud-private')->put('dotacion-signatures/a.jpg', $this->signatureJpeg());
        $this->createDelivery('dotacion-signatures/a.jpg', 2);

        $job = new GenerateSstDeliveryReportJob('job-123', [], $this->signatureOptions());
        $job->handle(app(SstDeliveryReportService::class));

        $status = cache()->get(GenerateSstDeliveryReportJob::cacheKey('job-123'));
        $this->assertSame('completed', $status['status']);
        Storage::disk('local')->assertExists($status['file_path']);
    }

    public function test_failed_job_marks_report_as_failed(): void
    {
        $job = new GenerateSstDeliveryReportJob('job-456', []);
        $job->failed(new \RuntimeException('Timeout'));

        $status = cache()->get(GenerateSstDeliveryReportJob::cacheKey('job-456'));
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Timeout', $status['error']);
    }
}
