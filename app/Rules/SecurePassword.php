<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecurePassword implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('La contraseña debe ser una cadena de texto.');
            return;
        }

        // Validar longitud mínima (12 caracteres)
        if (strlen($value) < 12) {
            $fail('La contraseña debe tener al menos 12 caracteres.');
            return;
        }

        // Validar longitud máxima (128 caracteres)
        if (strlen($value) > 128) {
            $fail('La contraseña es demasiado larga.');
            return;
        }

        // Validar al menos una letra mayúscula
        if (!preg_match('/[A-Z]/', $value)) {
            $fail('Debe incluir al menos una letra mayúscula.');
            return;
        }

        // Validar al menos una letra minúscula
        if (!preg_match('/[a-z]/', $value)) {
            $fail('Debe incluir al menos una letra minúscula.');
            return;
        }

        // Validar al menos un número
        if (!preg_match('/\d/', $value)) {
            $fail('Debe incluir al menos un número.');
            return;
        }

        // Validar al menos un símbolo especial
        if (!preg_match('/[!@#$%^&*()\-_=+\[\]{};:,.<>\/?]/', $value)) {
            $fail('Debe incluir al menos un símbolo especial (!@#$%^&*()-_=+[]{};:,.<>/?).');
            return;
        }
    }
}
