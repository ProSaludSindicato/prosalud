<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Constants\RequestSubtypes;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NormalizeRequestSubtypesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function paymentVerification(?string $subtype): RequestForm
    {
        return RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => array_filter([
                'proceso' => 'NUTRICION',
                'detalleNovedad' => 'Revisar incapacidad de mayo',
                'solicitudRelacionadaCon' => $subtype,
            ]),
            'created_at' => Carbon::parse('2026-01-15 10:00:00'),
            'status' => RequestStatuses::COMPLETED,
        ]);
    }

    private function storedSubtype(RequestForm $requestForm): ?string
    {
        return $requestForm->fresh()->payload['solicitudRelacionadaCon'] ?? null;
    }

    public function test_dry_run_reports_changes_without_saving(): void
    {
        $legacy = $this->paymentVerification('Compensación Final');

        $this->artisan('request-forms:normalize-subtypes', ['--dry-run' => true])
            ->expectsTable(['Valor actual', 'Valor estándar', 'Solicitudes'], [
                ['Compensación Final', RequestSubtypes::COMPENSACION_FINAL_LIQUIDACION, 1],
            ])
            ->expectsOutput('Se estandarizarían 1 solicitudes.')
            ->assertSuccessful();

        $this->assertSame('Compensación Final', $this->storedSubtype($legacy));
    }

    public function test_force_normalizes_legacy_values_and_keeps_the_rest_of_the_request(): void
    {
        $final = $this->paymentVerification('Compensación Final');
        $anual = $this->paymentVerification('Compensación Anual Diferida');
        $mensual = $this->paymentVerification('COMPENSACIÓN MENSUAL');

        $this->artisan('request-forms:normalize-subtypes', ['--force' => true])
            ->expectsOutput('Solicitudes estandarizadas: 3.')
            ->assertSuccessful();

        $this->assertSame(RequestSubtypes::COMPENSACION_FINAL_LIQUIDACION, $this->storedSubtype($final));
        $this->assertSame(RequestSubtypes::COMPENSACION_ANUAL_DIFERIDA_DESCANSO, $this->storedSubtype($anual));
        $this->assertSame(RequestSubtypes::COMPENSACION_MENSUAL, $this->storedSubtype($mensual));

        $fresh = $final->fresh();
        $this->assertSame('NUTRICION', $fresh->payload['proceso']);
        $this->assertSame('Revisar incapacidad de mayo', $fresh->payload['detalleNovedad']);
        $this->assertSame('2026-01-15 10:00:00', $fresh->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(RequestStatuses::COMPLETED, $fresh->status);
    }

    public function test_canonical_unrecognized_and_other_types_are_left_untouched(): void
    {
        $canonical = $this->paymentVerification(RequestSubtypes::INCAPACIDADES);
        $unrecognized = $this->paymentVerification('Algo Que No Existe');
        $withoutSubtype = $this->paymentVerification(null);
        $otherType = RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'payload' => ['solicitudRelacionadaCon' => 'Compensación Final'],
        ]);

        $this->artisan('request-forms:normalize-subtypes', ['--force' => true])
            ->expectsOutput('Valores no reconocidos (no se modifican, revisar manualmente):')
            ->expectsTable(['Valor', 'Solicitudes'], [['Algo Que No Existe', 1]])
            ->expectsOutput('No hay subtipos por estandarizar.')
            ->assertSuccessful();

        $this->assertSame(RequestSubtypes::INCAPACIDADES, $this->storedSubtype($canonical));
        $this->assertSame('Algo Que No Existe', $this->storedSubtype($unrecognized));
        $this->assertNull($this->storedSubtype($withoutSubtype));
        $this->assertSame('Compensación Final', $this->storedSubtype($otherType));
    }

    public function test_second_run_has_nothing_left_to_normalize(): void
    {
        $this->paymentVerification('Duplicado Colillas');

        $this->artisan('request-forms:normalize-subtypes', ['--force' => true])->assertSuccessful();

        $this->artisan('request-forms:normalize-subtypes', ['--force' => true])
            ->expectsOutput('No hay subtipos por estandarizar.')
            ->assertSuccessful();
    }

    public function test_production_requires_force_to_save(): void
    {
        $legacy = $this->paymentVerification('Compensación Final');
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('request-forms:normalize-subtypes')
            ->expectsOutput('En producción este comando requiere --force para guardar cambios.')
            ->assertFailed();

        $this->assertSame('Compensación Final', $this->storedSubtype($legacy));
    }

    public function test_production_with_force_saves_changes(): void
    {
        $legacy = $this->paymentVerification('Compensación Final');
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('request-forms:normalize-subtypes', ['--force' => true])->assertSuccessful();

        $this->assertSame(RequestSubtypes::COMPENSACION_FINAL_LIQUIDACION, $this->storedSubtype($legacy));
    }

    public function test_outside_production_asks_for_confirmation(): void
    {
        $legacy = $this->paymentVerification('Compensación Final');

        $this->artisan('request-forms:normalize-subtypes')
            ->expectsConfirmation('¿Estandarizar 1 solicitudes?', 'no')
            ->assertSuccessful();

        $this->assertSame('Compensación Final', $this->storedSubtype($legacy));

        $this->artisan('request-forms:normalize-subtypes')
            ->expectsConfirmation('¿Estandarizar 1 solicitudes?', 'yes')
            ->assertSuccessful();

        $this->assertSame(RequestSubtypes::COMPENSACION_FINAL_LIQUIDACION, $this->storedSubtype($legacy));
    }
}
