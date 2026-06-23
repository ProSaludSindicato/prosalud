<?php

namespace App\Rules;

use App\Services\RecaptchaService;
use Illuminate\Contracts\Validation\Rule;

class RecaptchaRule implements Rule
{
    private RecaptchaService $recaptchaService;

    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->recaptchaService = app(RecaptchaService::class);
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (! $this->recaptchaService->isEnabled()) {
            return true;
        }

        if (empty($value)) {
            $this->errorMessage = 'No se pudo verificar que no eres un bot. Por favor, intente nuevamente.';

            return false;
        }

        // Obtener expectedAction si se proporciona en el request
        $expectedAction = request()->input('recaptcha_action');

        $result = $this->recaptchaService->verify($value, $expectedAction, request()->ip());

        if (! $result['success']) {
            $this->errorMessage = $this->getErrorMessage($result);

            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return $this->errorMessage ?? 'La verificación de reCAPTCHA falló. Por favor, intente nuevamente.';
    }

    /**
     * Obtiene un mensaje de error amigable basado en los códigos de error
     */
    private function getErrorMessage(array $result): string
    {
        $errorCodes = $result['error_codes'] ?? [];

        if (empty($errorCodes)) {
            return 'La verificación de reCAPTCHA falló. Por favor, intente nuevamente.';
        }

        $errorMessages = [
            'missing-input-secret' => 'Error de configuración del servidor. Contacte al administrador.',
            'invalid-input-secret' => 'Error de configuración del servidor. Contacte al administrador.',
            'missing-input-response' => 'No se pudo verificar que no eres un bot. Por favor, intente nuevamente.',
            'invalid-input-response' => 'Token de verificación inválido. Por favor, intente nuevamente.',
            'bad-request' => 'Solicitud inválida. Por favor, intente nuevamente.',
            'timeout-or-duplicate' => 'El token de verificación expiró o ya fue usado. Por favor, intente nuevamente.',
        ];

        // Retornar el primer mensaje de error encontrado
        foreach ($errorCodes as $code) {
            if (isset($errorMessages[$code])) {
                return $errorMessages[$code];
            }
        }

        return 'La verificación de reCAPTCHA falló. Por favor, intente nuevamente.';
    }
}
