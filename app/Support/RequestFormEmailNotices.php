<?php

namespace App\Support;

use App\Constants\RequestTypes;
use App\Models\RequestForm;

class RequestFormEmailNotices
{
    /**
     * @return array<int, array{variant: string, icon: string, title: string, body: string}>
     */
    public static function noticesFor(RequestForm $form): array
    {
        return match ($form->request_type) {
            RequestTypes::COMPENSACION_DESCANSO,
            RequestTypes::COMPENSACION_ANUAL => self::compensacionNotices(),
            RequestTypes::VERIFICACION_PAGOS => self::verificacionPagosNotices(),
            default => [],
        };
    }

    /**
     * @return array<int, array{variant: string, icon: string, title: string, body: string}>
     */
    private static function compensacionNotices(): array
    {
        return [
            [
                'variant' => 'amber',
                'icon' => '📅',
                'title' => 'Fechas de procesamiento:',
                'body' => 'Si la solicitud es enviada ANTES del día 24 del mes, será revisada y en caso de ser aprobada será incluida junto con la compensación del mes en curso; en caso de recibirse posterior a esta fecha, se aplicará para ser revisada y sería incluida junto con la compensación del mes siguiente.',
            ],
            [
                'variant' => 'gray',
                'icon' => '🕐',
                'title' => 'Nota Importante:',
                'body' => 'El horario de revisión de solicitudes es de lunes a viernes de 7:00 a.m. a 4:00 p.m., cualquier registro vencido el citado horario, se entenderá presentado el siguiente día hábil. Se registran y asigna su revisión por orden de registro.',
            ],
        ];
    }

    /**
     * @return array<int, array{variant: string, icon: string, title: string, body: string}>
     */
    private static function verificacionPagosNotices(): array
    {
        return [
            [
                'variant' => 'amber',
                'icon' => '🕐',
                'title' => 'Tiempos de respuesta:',
                'body' => 'Su consulta será remitida al área encargada. Los tiempos estimados pueden ser de <strong>hasta 15 días hábiles</strong> para revisar su caso.<br><br><strong>Horario de revisión:</strong> Lunes a viernes de 7:00 a.m. a 5:00 p.m. Cualquier registro fuera de este horario se entenderá presentado el día hábil siguiente. Se registran y asignan por orden de llegada.',
            ],
        ];
    }
}
