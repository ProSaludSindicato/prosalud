<?php

namespace App\Support;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ConvenioDisplayFilename
{
    public static function fromTracking(ConvenioEmailTracking $tracking): string
    {
        return self::storageFileName($tracking, ConvenioPdfStage::Original);
    }

    public static function storageFileName(ConvenioEmailTracking $tracking, ConvenioPdfStage $stage): string
    {
        $parsedFromArchivo = self::parseStoredNombreArchivo($tracking->nombre_archivo);

        $location = self::resolveLocationLabel($tracking, $parsedFromArchivo);
        $nombreAfiliado = self::resolveAffiliateName($tracking, $parsedFromArchivo);
        $documento = preg_replace('/[^0-9]/', '', (string) $tracking->documento) ?: 'sin-documento';
        $periodo = self::resolvePeriodoFromEnviadoAt($tracking->enviado_at);

        $base = self::build($location, $nombreAfiliado, $documento, $periodo);

        if ($stage === ConvenioPdfStage::Original) {
            return $base;
        }

        $baseWithoutExt = preg_replace('/\.pdf$/i', '', $base) ?? $base;

        return $baseWithoutExt.' - '.$stage->value.'.pdf';
    }

    public static function build(
        string $location,
        string $nombreAfiliado,
        string $documento,
        ?string $periodo = null,
    ): string {
        $documento = preg_replace('/[^0-9]/', '', $documento) ?: 'sin-documento';

        $parts = array_values(array_filter([
            self::normalizeSegment($location),
            self::normalizeSegment($nombreAfiliado),
            $documento,
            self::normalizePeriodo($periodo),
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        if (count($parts) < 2) {
            return 'convenio.pdf';
        }

        return implode(' - ', $parts).'.pdf';
    }

    public static function resolvePeriodoFromEnviadoAt(CarbonInterface|string|null $enviadoAt): ?string
    {
        if ($enviadoAt === null || $enviadoAt === '') {
            return null;
        }

        $date = $enviadoAt instanceof CarbonInterface
            ? Carbon::instance($enviadoAt)
            : self::parseFlexibleDate((string) $enviadoAt);

        if (! $date instanceof Carbon) {
            return null;
        }

        return ConvenioSemesterPeriod::fromDate($date);
    }

    /**
     * @return array{location: string, nombre_afiliado: string, documento: string, periodo: string|null}|null
     */
    private static function parseStoredNombreArchivo(?string $nombreArchivo): ?array
    {
        if ($nombreArchivo === null || $nombreArchivo === '') {
            return null;
        }

        $parsed = ConvenioPreGeneratedPdfFilename::parse($nombreArchivo);
        if ($parsed === null) {
            return null;
        }

        return [
            'location' => $parsed['nombre_convenio'],
            'nombre_afiliado' => $parsed['nombre_afiliado'],
            'documento' => $parsed['documento'],
            'periodo' => $parsed['periodo'] ?? null,
        ];
    }

    /**
     * @param  array{location: string, nombre_afiliado: string, documento: string, periodo: string|null}|null  $parsedFromArchivo
     */
    private static function resolveLocationLabel(ConvenioEmailTracking $tracking, ?array $parsedFromArchivo): string
    {
        $sede = is_string($tracking->sede) ? trim($tracking->sede) : '';
        if ($sede !== '') {
            return strtoupper($sede);
        }

        if ($parsedFromArchivo !== null && $parsedFromArchivo['location'] !== '') {
            return strtoupper($parsedFromArchivo['location']);
        }

        $nombreConvenio = is_string($tracking->nombre_convenio) ? trim($tracking->nombre_convenio) : '';

        return $nombreConvenio !== '' ? strtoupper($nombreConvenio) : 'CONVENIO';
    }

    /**
     * @param  array{location: string, nombre_afiliado: string, documento: string, periodo: string|null}|null  $parsedFromArchivo
     */
    private static function resolveAffiliateName(ConvenioEmailTracking $tracking, ?array $parsedFromArchivo): string
    {
        $nombreAfiliado = is_string($tracking->nombre_afiliado) ? trim($tracking->nombre_afiliado) : '';
        if ($nombreAfiliado !== '' && strcasecmp($nombreAfiliado, 'No disponible') !== 0) {
            return strtoupper($nombreAfiliado);
        }

        if ($parsedFromArchivo !== null && $parsedFromArchivo['nombre_afiliado'] !== '') {
            return strtoupper($parsedFromArchivo['nombre_afiliado']);
        }

        $convenioData = is_array($tracking->convenio_data) ? $tracking->convenio_data : null;
        if ($convenioData !== null) {
            $fromData = trim(trim((string) ($convenioData['nombres'] ?? '')).' '.trim((string) ($convenioData['apellidos'] ?? '')));
            if ($fromData !== '') {
                return strtoupper($fromData);
            }
        }

        return 'SIN NOMBRE';
    }

    private static function normalizeSegment(string $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return strtoupper($normalized);
    }

    private static function normalizePeriodo(?string $periodo): ?string
    {
        if ($periodo === null) {
            return null;
        }

        $periodo = trim($periodo);
        if ($periodo === '') {
            return null;
        }

        if (preg_match('/^\d{4}[12]$/', $periodo) === 1) {
            return $periodo;
        }

        return null;
    }

    private static function parseFlexibleDate(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches) === 1) {
            return Carbon::createFromFormat('d/m/Y', sprintf('%02d/%02d/%04d', (int) $matches[1], (int) $matches[2], (int) $matches[3])) ?: null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
