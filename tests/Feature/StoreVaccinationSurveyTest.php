<?php

namespace Tests\Feature;

use App\Models\VaccinationSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreVaccinationSurveyTest extends TestCase
{
    use RefreshDatabase;

    private const MINIMAL_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        Storage::fake('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'tipo_documento' => 'CC',
            'numero_documento' => '1234567890',
            'fecha_nacimiento' => '1990-05-15',
            'primer_nombre' => 'JUAN',
            'segundo_nombre' => 'CARLOS',
            'primer_apellido' => 'PEREZ',
            'segundo_apellido' => 'GOMEZ',
            'hospital' => 'HSJDRionegro',
            'fecha_aplicacion_srp' => '2024-01-10',
            'fecha_aplicacion_sr' => null,
            'fecha_aplicacion_fiebre_amarilla' => null,
            'firma' => self::MINIMAL_PNG_DATA_URL,
        ], $overrides);
    }

    public function test_store_succeeds_with_png_signature(): void
    {
        $response = $this->postJson('/api/encuesta-vacunacion', $this->validPayload());

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Encuesta de vacunación registrada correctamente.',
            ]);

        $this->assertDatabaseHas('vaccination_surveys', [
            'tipo_documento' => 'CC',
            'numero_documento' => '1234567890',
            'hospital' => 'HSJDRIONEGRO',
            'primer_nombre' => 'JUAN',
            'primer_apellido' => 'PEREZ',
        ]);

        $survey = VaccinationSurvey::query()->first();
        $this->assertNotNull($survey);
        $this->assertNotEmpty($survey->firma_path);
        $this->assertStringEndsWith('.png', $survey->firma_path);
        Storage::disk('prosalud-private')->assertExists($survey->firma_path);
    }

    public function test_store_succeeds_with_jpeg_signature_from_signature_pad_default(): void
    {
        $jpegContent = UploadedFile::fake()->image('firma.jpg', 10, 10)->getContent();
        $jpegDataUrl = 'data:image/jpeg;base64,'.base64_encode($jpegContent);

        $response = $this->postJson('/api/encuesta-vacunacion', $this->validPayload([
            'firma' => $jpegDataUrl,
        ]));

        $response->assertCreated()
            ->assertJson(['success' => true]);

        $survey = VaccinationSurvey::query()->first();
        $this->assertNotNull($survey);
        $this->assertNotEmpty($survey->firma_path);
        $this->assertStringEndsWith('.jpg', $survey->firma_path);
        Storage::disk('prosalud-private')->assertExists($survey->firma_path);
    }

    public function test_store_rejects_signature_that_is_not_an_image_data_url(): void
    {
        $response = $this->postJson('/api/encuesta-vacunacion', $this->validPayload([
            'firma' => 'not-a-signature',
        ]));

        $response->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'message' => 'Errores de validación',
            ])
            ->assertJsonValidationErrors(['firma']);
    }

    public function test_store_rejects_missing_signature(): void
    {
        $payload = $this->validPayload();
        unset($payload['firma']);

        $response = $this->postJson('/api/encuesta-vacunacion', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['firma']);
    }

    public function test_store_allows_all_vaccine_dates_to_be_empty(): void
    {
        $response = $this->postJson('/api/encuesta-vacunacion', $this->validPayload([
            'fecha_aplicacion_srp' => '',
            'fecha_aplicacion_sr' => '',
            'fecha_aplicacion_fiebre_amarilla' => '',
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('vaccination_surveys', [
            'numero_documento' => '1234567890',
            'fecha_aplicacion_srp' => null,
            'fecha_aplicacion_sr' => null,
            'fecha_aplicacion_fiebre_amarilla' => null,
        ]);
    }
}
