<?php

namespace App\Support;

class ConvenioDataLabels
{
    /**
     * @return list<string>
     */
    public static function moneyKeys(): array
    {
        return [
            'basico',
            'auxilios',
            'manutencion',
            'provisiones',
            'valor_hora_diurna',
            'valor_hora_nocturna',
            'valor_hora_diurna_festiva',
            'valor_hora_nocturna_festiva',
            'auxilio_de_transporte',
            'auxilio_de_manutencion',
            'auxilio_de_encierro',
            'auxilio_de_rodamiento',
            'auxilio_especial',
            'auxilio_prosalud',
            'valor_auxilio_diurno',
            'valor_auxilio_recargo_nocturno',
            'valor_auxilio_recargo_festivo',
            'valor_auxilio_recargo_festivo_nocturno',
            't_basicos',
            't_auxilios',
            't_ingresos',
        ];
    }

    /**
     * @return list<string>
     */
    public static function integerKeys(): array
    {
        return [
            'horas',
        ];
    }

    public static function formatValue(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (in_array($key, self::moneyKeys(), true)) {
            return self::formatMoney($value);
        }

        if (in_array($key, self::integerKeys(), true)) {
            return self::formatInteger($value);
        }

        return $value;
    }

    public static function formatMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        return '$'.number_format((float) $value, 0, ',', '.');
    }

    public static function formatInteger(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        return number_format((float) $value, 0, ',', '.');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'numero_documento' => 'Número de documento',
            'apellidos' => 'Apellidos',
            'nombres' => 'Nombres',
            'proceso' => 'Proceso / convenio',
            'ciudad' => 'Ciudad',
            'sede' => 'Sede',
            'fecha_nacimiento' => 'Fecha de nacimiento',
            'lugar_nacimiento' => 'Lugar de nacimiento',
            'fecha_inicio' => 'Fecha de inicio',
            'fecha_finalizacion' => 'Fecha de finalización',
            'direccion' => 'Dirección',
            'celular' => 'Celular',
            'compensacion_basica_redactada' => 'Compensación básica redactada',
            'basico' => 'Básico',
            'auxilios' => 'Auxilios',
            'manutencion' => 'Manutención',
            'provisiones' => 'Provisiones',
            'horas' => 'Horas',
            'valor_hora_diurna' => 'Valor hora diurna',
            'valor_hora_nocturna' => 'Valor hora nocturna',
            'valor_hora_diurna_festiva' => 'Valor hora diurna festiva',
            'valor_hora_nocturna_festiva' => 'Valor hora nocturna festiva',
            'auxilio_de_transporte' => 'Auxilio de transporte',
            'auxilio_de_manutencion' => 'Auxilio de manutención',
            'auxilio_de_encierro' => 'Auxilio de encierro',
            'auxilio_de_rodamiento' => 'Auxilio de rodamiento',
            'auxilio_especial' => 'Auxilio especial',
            'auxilio_prosalud' => 'Auxilio Prosalud',
            'valor_auxilio_diurno' => 'Valor auxilio diurno',
            'valor_auxilio_recargo_nocturno' => 'Valor auxilio recargo nocturno',
            'valor_auxilio_recargo_festivo' => 'Valor auxilio recargo festivo',
            'valor_auxilio_recargo_festivo_nocturno' => 'Valor auxilio recargo festivo nocturno',
            'email' => 'Correo del afiliado (importación)',
            'send_email' => 'Enviar correo',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<int, array{key: string, label: string, value: mixed}>
     */
    public static function present(?array $data): array
    {
        if ($data === null || $data === []) {
            return [];
        }

        $labels = self::labels();
        $items = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'send_email') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No';
            } else {
                $value = self::formatValue($key, $value);
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        foreach ($data as $key => $value) {
            if (isset($labels[$key]) || $value === null || $value === '') {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                'value' => self::formatValue((string) $key, $value),
            ];
        }

        return $items;
    }
}
