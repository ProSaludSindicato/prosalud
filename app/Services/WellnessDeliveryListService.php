<?php

namespace App\Services;

use App\Models\User;
use App\Models\WellnessDeliveryListEntry;
use App\Models\WellnessDeliveryRequest;
use App\Models\WellnessDeliveryType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Listado de cupos por campaña (modo entrega_listado): plantilla, importación del Excel y control de cupos.
 */
class WellnessDeliveryListService
{
    /** Encabezados de la plantilla estándar, en orden. */
    public const TEMPLATE_HEADERS = [
        'documento_afiliado' => 'DOCUMENTO AFILIADO',
        'nombre_afiliado' => 'NOMBRE AFILIADO',
        'hospital' => 'HOSPITAL',
        'beneficiario' => 'NOMBRE BENEFICIARIO',
        'parentesco' => 'PARENTESCO',
        'edad' => 'EDAD',
        'cantidad' => 'CANTIDAD',
    ];

    /** Encabezados aceptados (normalizados: minúsculas, sin tildes ni signos) para cada campo. */
    private const COLUMN_ALIASES = [
        'documento_afiliado' => ['documento afiliado', 'documento', 'documento del afiliado', 'identificacion', 'numero de documento', 'numero documento', 'no documento', 'cedula', 'cc'],
        'nombre_afiliado' => ['nombre afiliado', 'nombre del afiliado', 'afiliado', 'nombres y apellidos afiliado'],
        'hospital' => ['hospital', 'sede', 'centro de trabajo'],
        'beneficiario' => ['nombre beneficiario', 'nombre del beneficiario', 'beneficiario', 'nombres y apellidos ben', 'nombres y apellidos beneficiario'],
        'parentesco' => ['parentesco'],
        'edad' => ['edad', 'edad beneficiario'],
        'cantidad' => ['cantidad', 'cantidad a entregar', 'cupos'],
    ];

    private const MAX_ROWS = 20000;

    private const MAX_CANTIDAD = 999;

    private const HEADER_SEARCH_ROWS = 10;

    /**
     * Genera la plantilla estándar y devuelve la ruta del archivo temporal.
     */
    public function buildTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listado');

        $col = 1;
        foreach (self::TEMPLATE_HEADERS as $field => $label) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($letter.'1', $field === 'documento_afiliado' ? $label.' *' : $label);
            $sheet->getColumnDimension($letter)->setWidth($field === 'nombre_afiliado' || $field === 'beneficiario' ? 38 : 20);
            $col++;
        }
        $lastLetter = Coordinate::stringFromColumnIndex(count(self::TEMPLATE_HEADERS));
        $headerStyle = $sheet->getStyle('A1:'.$lastLetter.'1');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F4E78');
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');

        $help = $spreadsheet->createSheet();
        $help->setTitle('Instrucciones');
        $lines = [
            ['INSTRUCCIONES PARA EL LISTADO DE ENTREGAS'],
            [''],
            ['1. Diligencie la hoja "Listado". La fila 1 son los encabezados; los datos empiezan en la fila 2.'],
            ['2. Una fila por cada beneficiario que recibe (ej. un afiliado con 3 hijos = 3 filas con el mismo documento).'],
            ['   Si la entrega es para el afiliado (sin beneficiarios), use una sola fila y deje vacío NOMBRE BENEFICIARIO.'],
            ['3. DOCUMENTO AFILIADO es obligatorio: solo el número, sin puntos ni espacios.'],
            ['4. CANTIDAD es opcional: unidades a entregar por esa fila. Si se deja vacía, vale 1.'],
            ['   El total de cupos del afiliado es la suma de CANTIDAD de todas sus filas.'],
            ['5. NOMBRE AFILIADO, HOSPITAL, PARENTESCO y EDAD son informativos (se muestran al entregar).'],
            ['6. Al volver a cargar el listado se reemplaza el anterior; las entregas ya registradas se conservan.'],
            [''],
            ['EJEMPLO'],
            array_values(self::TEMPLATE_HEADERS),
            ['4053599', 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYEL NOGUERA', 'HIJO', '5', '1'],
            ['4053599', 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYO NOGUERA', 'HIJO', '3', '1'],
            ['8103936', 'GIRALDO MORENO JUAN CAMILO', 'HLM - GRUPO 1', '', '', '', '2'],
        ];
        foreach ($lines as $index => $values) {
            foreach ($values as $colIndex => $value) {
                $help->setCellValueExplicit(Coordinate::stringFromColumnIndex($colIndex + 1).($index + 1), $value, DataType::TYPE_STRING);
            }
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getStyle('A12:G13')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(22);
        foreach (['B', 'D'] as $letter) {
            $help->getColumnDimension($letter)->setWidth(32);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'plantilla_listado_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Lee el Excel (primera hoja) y devuelve las filas válidas, los errores por fila y las columnas ignoradas.
     *
     * @return array{entries: list<array{documento_afiliado: string, clave: string, nombre_afiliado: ?string, hospital: ?string, beneficiario: ?string, parentesco: ?string, edad: ?string, cantidad: int}>, errors: list<string>, ignored_columns: list<string>}
     */
    public function parseFile(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        $headerIndex = null;
        $mapping = [];
        $ignored = [];
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            [$candidate, $candidateIgnored] = $this->mapHeaders($row);
            if (isset($candidate['documento_afiliado'])) {
                $headerIndex = $index;
                $mapping = $candidate;
                $ignored = $candidateIgnored;
                break;
            }
        }

        if ($headerIndex === null) {
            return [
                'entries' => [],
                'errors' => ['No se encontró la columna "DOCUMENTO AFILIADO" en las primeras filas de la primera hoja. Use la plantilla descargable.'],
                'ignored_columns' => [],
            ];
        }

        $entries = [];
        $errors = [];
        $clavesUsadas = [];
        $dataRows = array_slice($rows, $headerIndex + 1, null, true);

        if (count($dataRows) > self::MAX_ROWS) {
            return [
                'entries' => [],
                'errors' => ['El listado supera el máximo de '.self::MAX_ROWS.' filas.'],
                'ignored_columns' => $ignored,
            ];
        }

        foreach ($dataRows as $index => $row) {
            $excelRow = $index + 1;
            $value = fn (string $field): ?string => isset($mapping[$field]) ? $this->cellToString($row[$mapping[$field]] ?? null) : null;

            $documentoRaw = $value('documento_afiliado');
            $beneficiario = $value('beneficiario');
            $nombreAfiliado = $value('nombre_afiliado');
            $cantidadRaw = $value('cantidad');

            if ($documentoRaw === null && $beneficiario === null && $nombreAfiliado === null && $cantidadRaw === null) {
                continue;
            }

            $documento = $documentoRaw !== null ? $this->normalizeDocumento($documentoRaw) : '';
            if ($documento === '') {
                $errors[] = "Fila {$excelRow}: falta el documento del afiliado.";

                continue;
            }

            $cantidad = 1;
            if ($cantidadRaw !== null) {
                if (! preg_match('/^\d+$/', $cantidadRaw) || (int) $cantidadRaw < 1 || (int) $cantidadRaw > self::MAX_CANTIDAD) {
                    $errors[] = "Fila {$excelRow}: la cantidad «{$cantidadRaw}» no es válida (debe ser un número entero entre 1 y ".self::MAX_CANTIDAD.').';

                    continue;
                }
                $cantidad = (int) $cantidadRaw;
            }

            $base = $documento.'|'.($beneficiario !== null ? $this->normalizeText($beneficiario) : 'afiliado');
            $clave = $base;
            $ordinal = 1;
            while (isset($clavesUsadas[$clave])) {
                $ordinal++;
                $clave = $base.'#'.$ordinal;
            }
            $clavesUsadas[$clave] = true;

            $entries[] = [
                'documento_afiliado' => $documento,
                'clave' => Str::limit($clave, 300, ''),
                'nombre_afiliado' => $nombreAfiliado !== null ? Str::limit($nombreAfiliado, 200, '') : null,
                'hospital' => ($hospital = $value('hospital')) !== null ? Str::limit($hospital, 150, '') : null,
                'beneficiario' => $beneficiario !== null ? Str::limit($beneficiario, 200, '') : null,
                'parentesco' => ($parentesco = $value('parentesco')) !== null ? Str::limit($parentesco, 50, '') : null,
                'edad' => ($edad = $value('edad')) !== null ? Str::limit($edad, 10, '') : null,
                'cantidad' => $cantidad,
            ];
        }

        if ($entries === [] && $errors === []) {
            $errors[] = 'El listado no tiene filas con datos.';
        }

        return ['entries' => $entries, 'errors' => $errors, 'ignored_columns' => $ignored];
    }

    /**
     * Reemplaza el listado de la campaña con las filas dadas.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{archivo: ?string, cargado_at: ?string, afiliados: int, filas: int, cupos: int}
     */
    public function replaceList(WellnessDeliveryType $type, array $entries, string $fileName, ?User $user): array
    {
        DB::transaction(function () use ($type, $entries, $fileName, $user) {
            $type->listEntries()->delete();

            $now = now();
            foreach (array_chunk($entries, 500) as $chunk) {
                WellnessDeliveryListEntry::insert(array_map(fn (array $entry) => $entry + [
                    'wellness_delivery_type_id' => $type->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $type->update([
                'listado_archivo_nombre' => Str::limit($fileName, 255, ''),
                'listado_cargado_at' => $now,
                'listado_cargado_por' => $user?->id,
            ]);
        });

        return $this->summary($type->refresh());
    }

    /**
     * Resumen del listado cargado para una campaña.
     *
     * @return array{archivo: ?string, cargado_at: ?string, afiliados: int, filas: int, cupos: int}
     */
    public function summary(WellnessDeliveryType $type): array
    {
        $stats = $type->listEntries()
            ->toBase()
            ->selectRaw('COUNT(*) as filas, COUNT(DISTINCT documento_afiliado) as afiliados, COALESCE(SUM(cantidad), 0) as cupos')
            ->first();

        return [
            'archivo' => $type->listado_archivo_nombre,
            'cargado_at' => $type->listado_cargado_at?->toISOString(),
            'afiliados' => (int) ($stats->afiliados ?? 0),
            'filas' => (int) ($stats->filas ?? 0),
            'cupos' => (int) ($stats->cupos ?? 0),
        ];
    }

    /**
     * Cupos del afiliado en la campaña. Devuelve null si el documento no está en el listado.
     *
     * @return array{nombre_afiliado: ?string, hospital: ?string, cupo_total: int, cupo_entregado: int, cupo_disponible: int, beneficiarios: list<array{clave: string, beneficiario: ?string, parentesco: ?string, edad: ?string, cantidad: int, entregado: bool}>}|null
     */
    public function quotaFor(WellnessDeliveryType $type, string $documento, bool $lock = false): ?array
    {
        $documento = $this->normalizeDocumento($documento);
        $query = $type->listEntries()->where('documento_afiliado', $documento)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $entries = $query->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $requests = WellnessDeliveryRequest::query()
            ->where('wellness_delivery_type_id', $type->id)
            ->where('documento_afiliado', $documento)
            ->whereIn('estado', ['pendiente', 'entregado'])
            ->get(['id', 'beneficiarios', 'cantidad_entregada']);

        $entregadoPorClave = [];
        foreach ($requests as $request) {
            foreach ((array) $request->beneficiarios as $item) {
                if (is_array($item) && isset($item['clave'])) {
                    $entregadoPorClave[$item['clave']] = ($entregadoPorClave[$item['clave']] ?? 0) + (int) ($item['cantidad'] ?? 1);
                }
            }
        }

        $cupoTotal = (int) $entries->sum('cantidad');
        $cupoEntregado = (int) $requests->sum(fn (WellnessDeliveryRequest $r) => $r->cantidad_entregada ?? 1);

        return [
            'nombre_afiliado' => $entries->pluck('nombre_afiliado')->filter()->first(),
            'hospital' => $entries->pluck('hospital')->filter()->first(),
            'cupo_total' => $cupoTotal,
            'cupo_entregado' => $cupoEntregado,
            'cupo_disponible' => max(0, $cupoTotal - $cupoEntregado),
            'beneficiarios' => $entries->map(fn (WellnessDeliveryListEntry $entry) => [
                'clave' => $entry->clave,
                'beneficiario' => $entry->beneficiario,
                'parentesco' => $entry->parentesco,
                'edad' => $entry->edad,
                'cantidad' => $entry->cantidad,
                'entregado' => ($entregadoPorClave[$entry->clave] ?? 0) >= $entry->cantidad,
            ])->values()->all(),
        ];
    }

    /**
     * Registra la entrega de los cupos seleccionados (estado entregado), validando listado y cupos disponibles.
     *
     * @param  list<string>  $claves
     * @param  array{nombre_afiliado?: ?string, hospital?: ?string, ip_address?: ?string, user_agent?: ?string, observaciones?: ?string}  $context
     *
     * @throws ValidationException
     */
    public function registerDelivery(WellnessDeliveryType $type, string $documento, array $claves, string $firma, User $user, array $context = []): WellnessDeliveryRequest
    {
        $documento = $this->normalizeDocumento($documento);

        return DB::transaction(function () use ($type, $documento, $claves, $firma, $user, $context) {
            $quota = $this->quotaFor($type, $documento, lock: true);

            if ($quota === null) {
                throw ValidationException::withMessages([
                    'documento_afiliado' => "El documento {$documento} no está en el listado de la campaña «{$type->nombre}».",
                ]);
            }

            $porClave = collect($quota['beneficiarios'])->keyBy('clave');
            $claves = array_values(array_unique($claves));
            $seleccion = [];
            foreach ($claves as $clave) {
                $item = $porClave->get($clave);
                if ($item === null) {
                    throw ValidationException::withMessages([
                        'beneficiarios' => 'Uno de los beneficiarios seleccionados no pertenece al listado de este afiliado.',
                    ]);
                }
                if ($item['entregado']) {
                    $nombre = $item['beneficiario'] ?: 'el afiliado';
                    throw ValidationException::withMessages([
                        'beneficiarios' => "La entrega para {$nombre} ya fue registrada en esta campaña.",
                    ]);
                }
                $seleccion[] = $item;
            }

            $cantidad = (int) array_sum(array_column($seleccion, 'cantidad'));
            if ($cantidad > $quota['cupo_disponible']) {
                throw ValidationException::withMessages([
                    'beneficiarios' => "El afiliado tiene {$quota['cupo_disponible']} entrega(s) disponible(s) en esta campaña y se intentan registrar {$cantidad}.",
                ]);
            }

            return WellnessDeliveryRequest::create([
                'wellness_delivery_type_id' => $type->id,
                'documento_afiliado' => $documento,
                'nombre_afiliado' => $quota['nombre_afiliado'] ?? ($context['nombre_afiliado'] ?? ''),
                'hospital' => $quota['hospital'] ?? ($context['hospital'] ?? null),
                'fecha_expedicion' => null,
                'beneficiarios' => array_map(fn (array $item) => [
                    'clave' => $item['clave'],
                    'beneficiario' => $item['beneficiario'] ?: ($quota['nombre_afiliado'] ?? 'Afiliado'),
                    'parentesco' => $item['parentesco'],
                    'edad' => $item['edad'],
                    'cantidad' => $item['cantidad'],
                ], $seleccion),
                'firma' => '',
                'firma_recibido' => $firma,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'estado' => 'entregado',
                'cantidad_entregada' => $cantidad,
                'observaciones' => $context['observaciones'] ?? null,
                'entregado_por_user_id' => $user->id,
            ]);
        });
    }

    /**
     * Deja solo letras y números del documento (quita puntos, comas, espacios y guiones).
     */
    public function normalizeDocumento(string $documento): string
    {
        return mb_strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $documento) ?? '');
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function mapHeaders(array $row): array
    {
        $mapping = [];
        $ignored = [];
        foreach ($row as $index => $header) {
            $label = $this->cellToString($header);
            if ($label === null) {
                continue;
            }
            $normalized = $this->normalizeText($label);
            $field = null;
            foreach (self::COLUMN_ALIASES as $candidate => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $field = $candidate;
                    break;
                }
            }
            if ($field !== null && ! isset($mapping[$field])) {
                $mapping[$field] = $index;
            } else {
                $ignored[] = $label;
            }
        }

        return [$mapping, $ignored];
    }

    private function cellToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            $value = (string) (int) $value;
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9 ]/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
