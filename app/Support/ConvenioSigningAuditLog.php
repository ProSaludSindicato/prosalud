<?php

namespace App\Support;

class ConvenioSigningAuditLog
{
    /**
     * @param  array<string, mixed>|null  $auditLog
     * @return array<string, mixed>|null
     */
    public static function present(?array $auditLog): ?array
    {
        if ($auditLog === null) {
            return null;
        }

        $events = [];
        foreach ($auditLog['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = is_string($event['type'] ?? null) ? $event['type'] : '';
            $events[] = array_merge($event, [
                'label' => self::eventLabel($type, $event),
                'detail' => self::eventDetail($event),
            ]);
        }

        $summary = is_array($auditLog['summary'] ?? null) ? $auditLog['summary'] : [];
        $summary = self::enrichSummary($summary, $events);

        return array_merge($auditLog, [
            'events' => $events,
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function eventLabel(string $type, array $event = []): string
    {
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        if ($type === 'page_navigated' && ($metadata['reason'] ?? null) === 'signature_page') {
            return 'Llegó a la página de firma';
        }

        return match ($type) {
            'document_opened' => 'Documento abierto',
            'page_navigated' => 'Navegó en el documento',
            'signature_area_clicked' => 'Abrió el recuadro de firma',
            'signature_drawn' => 'Dibujó su firma',
            'signature_uploaded' => 'Subió una imagen de firma',
            'signature_positioned' => 'Colocó la firma en el documento',
            'signature_cleared' => 'Borró la firma',
            'terms_accepted' => 'Autorizó tratamiento de datos personales',
            'document_submitted' => 'Envió el convenio firmado',
            'document_confirmed' => 'Confirmó el envío',
            'document_downloaded' => 'Descargó el documento firmado',
            default => 'Actividad en el visor',
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function eventDetail(array $event): ?string
    {
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $page = self::numericValue($metadata['page'] ?? $metadata['signaturePage'] ?? null);

        if ($page === null) {
            return null;
        }

        return "Página {$page}";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public static function enrichSummary(array $summary, array $events): array
    {
        if (! isset($summary['signatureMethod']) || $summary['signatureMethod'] === null || $summary['signatureMethod'] === '') {
            foreach (array_reverse($events) as $event) {
                $type = $event['type'] ?? null;
                if ($type === 'signature_drawn') {
                    $summary['signatureMethod'] = 'draw';
                    break;
                }
                if ($type === 'signature_uploaded') {
                    $summary['signatureMethod'] = 'upload';
                    break;
                }
            }
        }

        if (! isset($summary['signaturePage']) || $summary['signaturePage'] === null || $summary['signaturePage'] === '') {
            foreach (array_reverse($events) as $event) {
                $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
                $page = self::numericValue($metadata['page'] ?? $metadata['signaturePage'] ?? null);
                if ($page !== null) {
                    $summary['signaturePage'] = $page;
                    break;
                }
            }
        }

        $summary['signatureMethodLabel'] = match ($summary['signatureMethod'] ?? null) {
            'draw' => 'Dibujada',
            'upload' => 'Imagen subida',
            default => null,
        };

        return $summary;
    }

    private static function numericValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return null;
    }
}
