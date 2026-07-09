<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SocioDemographicSurveySignatureValidationTest extends TestCase
{
    private const MINIMAL_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_signature_file_rule_rejects_non_image_content(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'firma-txt-');
        file_put_contents($tempPath, 'not-an-image');

        $invalidFile = new UploadedFile(
            $tempPath,
            'firma.png',
            'image/png',
            null,
            true
        );

        $validator = Validator::make(
            ['files' => ['firma' => $invalidFile]],
            ['files.firma' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']
        );

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('files.firma', $validator->errors()->toArray());
    }

    public function test_signature_file_rule_accepts_jpeg_content_named_as_png(): void
    {
        $jpegContent = UploadedFile::fake()->image('firma.jpg', 100, 100)->getContent();
        $tempPath = tempnam(sys_get_temp_dir(), 'firma-jpeg-');
        file_put_contents($tempPath, $jpegContent);

        $jpegFileNamedAsPng = new UploadedFile(
            $tempPath,
            'firma.png',
            'image/png',
            null,
            true
        );

        $validator = Validator::make(
            ['files' => ['firma' => $jpegFileNamedAsPng]],
            ['files.firma' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_signature_file_rule_accepts_jpeg_content(): void
    {
        $jpegContent = UploadedFile::fake()->image('firma.jpg', 100, 100)->getContent();
        $tempPath = tempnam(sys_get_temp_dir(), 'firma-jpeg-');
        file_put_contents($tempPath, $jpegContent);

        $jpegFile = new UploadedFile(
            $tempPath,
            'firma.jpg',
            'image/jpeg',
            null,
            true
        );

        $validator = Validator::make(
            ['files' => ['firma' => $jpegFile]],
            ['files.firma' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_signature_file_rule_accepts_png_content(): void
    {
        $pngBinary = base64_decode(
            substr(self::MINIMAL_PNG_DATA_URL, strpos(self::MINIMAL_PNG_DATA_URL, ',') + 1),
            true
        );

        $pngFile = UploadedFile::fake()->createWithContent('firma.png', $pngBinary);

        $validator = Validator::make(
            ['files' => ['firma' => $pngFile]],
            ['files.firma' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']
        );

        $this->assertTrue($validator->passes());
    }
}
