<?php

namespace App\Support;

class ConvenioHistoryUi
{
    /**
     * @return array<string, mixed>
     */
    public static function metadata(bool $digitalSigningEnabled): array
    {
        $estadoFiltros = [
            ['value' => 'todos', 'label' => 'Todos'],
            ['value' => 'pendiente', 'label' => 'Pendiente'],
            ['value' => 'enviado', 'label' => 'Enviado'],
            ['value' => 'fallido', 'label' => 'Fallido'],
            ['value' => 'verificacion', 'label' => 'Verificación (modo test)'],
        ];

        if ($digitalSigningEnabled) {
            $estadoFiltros = array_merge($estadoFiltros, [
                ['value' => 'firma_pendiente_firma', 'label' => 'Firma pendiente'],
                ['value' => 'firma_firmado_afiliado', 'label' => 'Firmado afiliado'],
                ['value' => 'firma_completado', 'label' => 'Firma completada'],
            ]);
        }

        return [
            'tabs' => [
                ['id' => 'generate', 'label' => 'Generar convenio'],
                ['id' => 'import_bulk', 'label' => 'Importación masiva'],
                ['id' => 'history', 'label' => 'Historial y seguimiento'],
            ],
            'bulk_actions' => [
                [
                    'id' => 'resend',
                    'label' => 'Reenviar seleccionados',
                    'endpoint' => '/api/convenios-manual/resend-emails',
                    'method' => 'POST',
                    'payload_key' => 'tracking_ids',
                ],
            ],
            'estado_filtros' => $estadoFiltros,
            'deprecated_endpoints' => [
                [
                    'endpoint' => '/api/convenios-manual/send-bulk-emails',
                    'replacement' => 'Use importación masiva con send_email=true para nuevos convenios, o seleccione registros en el historial y use resend-emails.',
                ],
            ],
            'alternative_flows' => [
                'generate_and_send' => '/api/convenios-manual/import-bulk',
                'bulk_resend' => '/api/convenios-manual/resend-emails',
                'history' => '/api/convenios-manual/email-history',
            ],
        ];
    }
}
