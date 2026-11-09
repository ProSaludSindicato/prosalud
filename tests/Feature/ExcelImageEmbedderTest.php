<?php

namespace Tests\Feature;

use App\Services\ExcelImageEmbedder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ExcelImageEmbedderTest extends TestCase
{
    private ExcelImageEmbedder $images;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        Storage::fake('local');
        $this->images = new ExcelImageEmbedder;
    }

    protected function tearDown(): void
    {
        $this->images->cleanup();

        parent::tearDown();
    }

    private function image(string $type = 'jpeg', int $width = 1710, int $height = 260): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image, null, 90);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    public function test_prepare_from_disk_shrinks_image_and_reuses_the_same_file(): void
    {
        Storage::disk('prosalud-private')->put('firmas/a.jpg', $this->image());

        $first = $this->images->prepareFromDisk('prosalud-private', 'firmas/a.jpg', 100, 50);
        Storage::disk('prosalud-private')->delete('firmas/a.jpg');
        $second = $this->images->prepareFromDisk('prosalud-private', 'firmas/a.jpg', 100, 50);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        [$width, $height, $type] = getimagesize($first);
        $this->assertSame(300, $width);
        $this->assertLessThanOrEqual(150, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_png_sources_stay_png(): void
    {
        Storage::disk('prosalud-private')->put('firmas/a.png', $this->image('png'));

        $file = $this->images->prepareFromDisk('prosalud-private', 'firmas/a.png', 100, 50);

        $this->assertSame(IMAGETYPE_PNG, getimagesize($file)[2]);
    }

    public function test_prepare_from_disk_falls_back_to_next_disk(): void
    {
        Storage::disk('local')->put('firmas/a.jpg', $this->image());

        $this->assertNotNull($this->images->prepareFromDisk(['prosalud-private', 'local'], 'firmas/a.jpg', 100, 50));
    }

    public function test_missing_invalid_or_oversized_sources_return_null(): void
    {
        Storage::disk('prosalud-private')->put('firmas/texto.jpg', 'no es una imagen');
        Storage::disk('prosalud-private')->put('firmas/grande.jpg', str_repeat('a', ExcelImageEmbedder::MAX_SOURCE_BYTES + 1));

        $this->assertNull($this->images->prepareFromDisk('prosalud-private', 'firmas/no-existe.jpg', 100, 50));
        $this->assertNull($this->images->prepareFromDisk('prosalud-private', 'firmas/texto.jpg', 100, 50));
        $this->assertNull($this->images->prepareFromDisk('prosalud-private', 'firmas/grande.jpg', 100, 50));
        $this->assertNull($this->images->prepareFromDisk('prosalud-private', null, 100, 50));
    }

    public function test_prefetch_from_s3_disk_downloads_through_presigned_urls_concurrently(): void
    {
        config(['filesystems.disks.prosalud-private.driver' => 's3']);
        Storage::disk('prosalud-private')->buildTemporaryUrlsUsing(fn (string $path) => "https://r2.test/{$path}?signature=x");
        Http::fake([
            'r2.test/firmas/a.jpg*' => Http::response($this->image()),
            'r2.test/firmas/b.jpg*' => Http::response($this->image()),
            'r2.test/*' => Http::response('', 404),
        ]);

        $this->images->prefetchFromDisk('prosalud-private', ['firmas/a.jpg', 'firmas/b.jpg', 'firmas/a.jpg', null, 'firmas/c.jpg'], 100, 50);

        Http::assertSentCount(3);
        $this->assertNotNull($this->images->prepareFromDisk('prosalud-private', 'firmas/a.jpg', 100, 50));
        $this->assertNotNull($this->images->prepareFromDisk('prosalud-private', 'firmas/b.jpg', 100, 50));
        $this->assertNull($this->images->prepareFromDisk('prosalud-private', 'firmas/c.jpg', 100, 50));
        Http::assertSentCount(3);
    }

    public function test_prefetch_from_disk_skips_non_s3_disks(): void
    {
        Http::fake();

        $this->images->prefetchFromDisk('local', ['firmas/a.jpg'], 100, 50);

        Http::assertNothingSent();
    }

    public function test_prefetched_urls_are_not_downloaded_again(): void
    {
        Http::fake([
            'cdn.test/ok.jpg' => Http::response($this->image('jpeg', 4000, 3000)),
            'cdn.test/missing.jpg' => Http::response('', 404),
        ]);

        $this->images->prefetchUrls(['https://cdn.test/ok.jpg', 'https://cdn.test/missing.jpg', null], 100, 75);
        $ok = $this->images->prepareFromUrl('https://cdn.test/ok.jpg', 100, 75);
        $missing = $this->images->prepareFromUrl('https://cdn.test/missing.jpg', 100, 75);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://cdn.test/ok.jpg');
        $this->assertNull($missing);
        [$width, $height] = getimagesize($ok);
        $this->assertSame(300, $width);
        $this->assertSame(225, $height);
    }

    public function test_prepare_from_content_and_embed_place_drawing_once_per_source(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $content = $this->image();

        $first = $this->images->prepareFromContent('firma-1', $content, 150, 50);
        $second = $this->images->prepareFromContent('firma-1', $content, 150, 50);

        $this->assertSame($first, $second);
        $this->assertTrue($this->images->embed($sheet, $first, 'A1', 150, 50));
        $this->assertTrue($this->images->embed($sheet, $second, 'A2', 150, 50));
        $this->assertFalse($this->images->embed($sheet, null, 'A3', 150, 50));
        $this->assertCount(2, $sheet->getDrawingCollection());
    }

    public function test_cleanup_deletes_temporary_files(): void
    {
        $file = $this->images->prepareFromContent('firma-1', $this->image(), 100, 50);

        $this->images->cleanup();

        $this->assertFileDoesNotExist($file);
    }
}
