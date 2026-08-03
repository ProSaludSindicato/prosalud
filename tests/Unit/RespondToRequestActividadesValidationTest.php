<?php

namespace Tests\Unit;

use App\Http\Requests\RespondToRequestRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RespondToRequestActividadesValidationTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'status' => 'COMPLETED',
            'email_subject' => 'Certificado de convenio',
            'email_body' => 'Su certificado se adjunta.',
            'actividades' => ['Actividad de prueba'],
        ], $overrides);
    }

    public function test_actividad_with_max_allowed_chars_passes_validation(): void
    {
        $payload = $this->basePayload([
            'actividades' => [str_repeat('a', RespondToRequestRequest::MAX_ACTIVIDAD_CHARS)],
        ]);

        $request = RespondToRequestRequest::create('/api/requests/1/respond', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_actividad_exceeding_max_chars_fails_validation(): void
    {
        $payload = $this->basePayload([
            'actividades' => [str_repeat('a', RespondToRequestRequest::MAX_ACTIVIDAD_CHARS + 1)],
        ]);

        $request = RespondToRequestRequest::create('/api/requests/1/respond', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('actividades.0'));
        $this->assertSame(
            'Cada actividad no puede exceder '.RespondToRequestRequest::MAX_ACTIVIDAD_CHARS.' caracteres.',
            $validator->errors()->first('actividades.0')
        );
    }
}
