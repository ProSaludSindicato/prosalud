<?php

namespace App\Services;

use App\Models\KitBienestarFileVersion;
use App\Models\WellnessDeliveryListEntry;
use App\Models\WellnessDeliveryType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Listado de personas habilitadas para las campañas en modo "Inscripción con listado".
 *
 * El Excel se lee por encabezados (solo el documento es obligatorio), de modo que sirve tanto para
 * kits con beneficiarios como para listados simples (ej. invitados a una fiesta). Los archivos antiguos
 * sin encabezados reconocibles se leen con el formato por posición (A..G).
 */
class KitBienestarService
{
    private const EXCEL_FILE_PATH = 'resources/templates/INFORMACION_PARA_KIT_ESCOLARES.xlsx'; // Fallback para compatibilidad

    /** Encabezados de la plantilla estándar, en orden. */
    public const TEMPLATE_HEADERS = [
        'documento' => 'DOCUMENTO',
        'nombre' => 'NOMBRE',
        'hospital' => 'HOSPITAL',
        'fecha_expedicion' => 'FECHA EXPEDICION',
        'beneficiario' => 'NOMBRE BENEFICIARIO',
        'parentesco' => 'PARENTESCO',
        'edad' => 'EDAD',
    ];

    /** Encabezados aceptados (normalizados: minúsculas, sin tildes ni signos) para cada campo. */
    private const COLUMN_ALIASES = [
        'documento' => ['documento', 'documento afiliado', 'documento del afiliado', 'identificacion', 'numero de documento', 'numero documento', 'no documento', 'cedula', 'cc'],
        'nombre' => ['nombre', 'nombres', 'nombre completo', 'nombres y apellidos', 'nombre afiliado', 'nombre del afiliado', 'afiliado', 'nombre invitado', 'invitado', 'persona'],
        'hospital' => ['hospital', 'sede', 'centro de trabajo'],
        'fecha_expedicion' => ['fecha expedicion', 'fecha de expedicion', 'expedicion', 'fecha exp', 'f exp', 'f expedicion'],
        'beneficiario' => ['nombre beneficiario', 'nombre del beneficiario', 'beneficiario', 'nombres y apellidos ben', 'nombres y apellidos beneficiario', 'ben'],
        'parentesco' => ['parentesco'],
        'edad' => ['edad', 'edad beneficiario'],
    ];

    /** Formato antiguo por posición (0-based). */
    private const LEGACY_COLUMNS = [
        'documento' => 0,
        'nombre' => 1,
        'hospital' => 2,
        'fecha_expedicion' => 3,
        'beneficiario' => 4,
        'parentesco' => 5,
        'edad' => 6,
    ];

    /** Palabras clave para encabezados que no coinciden exactamente con un alias (ej. "FECHA EXPEDICION CC"). */
    private const COLUMN_KEYWORDS = [
        'fecha_expedicion' => 'expedicion',
        'beneficiario' => 'beneficiario',
        'parentesco' => 'parentesco',
        'documento' => 'documento',
        'hospital' => 'hospital',
        'edad' => 'edad',
    ];

    private const HEADER_SEARCH_ROWS = 10;

    private const MAX_ROWS = 20000;

    private const MAX_ERRORS = 50;

    /**
     * Autentica a la persona contra el listado activo.
     * Si la fila del listado tiene fecha de expedición, debe coincidir; si está vacía (formato con encabezados),
     * basta con el documento. No valida tipo de documento.
     *
     * @return array<string, mixed>|null
     */
    public function authenticateAndGetKitBienestar(
        string $documento,
        string $fechaExpedicion,
    ): ?array {
        $normalizedDocumento = $this->normalizeDocumento($documento);
        $normalizedFechaExpedicion = $this->parseFecha($fechaExpedicion);

        $cacheKey = sprintf(
            'kit_bienestar:auth:%s:%s',
            md5($normalizedDocumento),
            md5($normalizedFechaExpedicion ?? '')
        );

        $cachedData = Cache::tags(['kit_bienestar'])->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }

        if ($normalizedDocumento === '') {
            return null;
        }

        return Cache::tags(['kit_bienestar'])->remember($cacheKey, now()->addHours(24), function () use ($normalizedDocumento, $normalizedFechaExpedicion) {
            return $this->withExcelFile(function (string $excelPath) use ($normalizedDocumento, $normalizedFechaExpedicion) {
                $originalMemoryLimit = ini_get('memory_limit');
                $originalMaxExecutionTime = ini_get('max_execution_time');

                try {
                    ini_set('memory_limit', '512M');
                    set_time_limit(60);

                    $parsed = $this->parseFile($excelPath);

                    $matches = array_values(array_filter($parsed['rows'], fn (array $row) => $row['documento'] === $normalizedDocumento
                        && ($row['fecha_expedicion'] === null || $row['fecha_expedicion'] === $normalizedFechaExpedicion)));

                    if ($matches === []) {
                        Log::info('Persona no encontrada en el listado de inscripción', [
                            'documento' => $normalizedDocumento,
                            'fecha_expedicion' => $normalizedFechaExpedicion,
                        ]);

                        return null;
                    }

                    return $this->buildAuthPayload($matches);
                } catch (\Throwable $e) {
                    Log::error('Error al leer el listado de inscripción', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    return null;
                } finally {
                    if ($originalMemoryLimit !== false && $originalMemoryLimit !== null) {
                        ini_set('memory_limit', (string) $originalMemoryLimit);
                    }
                    if ($originalMaxExecutionTime !== false && $originalMaxExecutionTime !== null) {
                        set_time_limit((int) $originalMaxExecutionTime);
                    }
                }
            }, null);
        });
    }

    /**
     * Autentica a la persona contra el listado propio de la campaña (cargado desde el formulario de la campaña).
     * Si la fila tiene fecha de expedición debe coincidir; si está vacía, basta con el documento.
     * Con $soloDocumento (registro hecho por personal interno) no se compara la fecha.
     *
     * @return array<string, mixed>|null
     */
    public function authenticateForCampaign(WellnessDeliveryType $type, string $documento, ?string $fechaExpedicion, bool $soloDocumento = false): ?array
    {
        $normalizedDocumento = $this->normalizeDocumento($documento);
        if ($normalizedDocumento === '') {
            return null;
        }
        $normalizedFecha = $fechaExpedicion !== null ? $this->parseFecha($fechaExpedicion) : null;

        $matches = $type->listEntries()
            ->where('documento_afiliado', $normalizedDocumento)
            ->when(! $soloDocumento, fn ($query) => $query->where(function ($query) use ($normalizedFecha) {
                $query->whereNull('fecha_expedicion');
                if ($normalizedFecha !== null) {
                    $query->orWhere('fecha_expedicion', $normalizedFecha);
                }
            }))
            ->orderBy('id')
            ->get()
            ->map(fn (WellnessDeliveryListEntry $entry) => [
                'documento' => $entry->documento_afiliado,
                'nombre' => $entry->nombre_afiliado,
                'hospital' => $entry->hospital,
                'fecha_expedicion' => $entry->fecha_expedicion,
                'beneficiario' => $entry->beneficiario,
                'parentesco' => $entry->parentesco,
                'edad' => $entry->edad,
            ])
            ->all();

        return $matches === [] ? null : $this->buildAuthPayload($matches);
    }

    /**
     * Convierte las filas leídas del Excel en filas del listado de la campaña (una inscripción por fila).
     *
     * @param  list<array{documento: string, nombre: ?string, hospital: ?string, fecha_expedicion: ?string, beneficiario: ?string, parentesco: ?string, edad: ?string}>  $rows
     * @return list<array{documento_afiliado: string, clave: string, nombre_afiliado: ?string, hospital: ?string, beneficiario: ?string, parentesco: ?string, edad: ?string, fecha_expedicion: ?string, cantidad: int}>
     */
    public function toListEntries(array $rows): array
    {
        $clavesUsadas = [];
        $entries = [];
        foreach ($rows as $row) {
            $base = $row['documento'].'|'.($row['beneficiario'] !== null ? $this->normalizeText($row['beneficiario']) : 'persona');
            $clave = $base;
            $ordinal = 1;
            while (isset($clavesUsadas[$clave])) {
                $ordinal++;
                $clave = $base.'#'.$ordinal;
            }
            $clavesUsadas[$clave] = true;

            $entries[] = [
                'documento_afiliado' => $row['documento'],
                'clave' => Str::limit($clave, 300, ''),
                'nombre_afiliado' => $row['nombre'],
                'hospital' => $row['hospital'],
                'beneficiario' => $row['beneficiario'],
                'parentesco' => $row['parentesco'],
                'edad' => $row['edad'],
                'fecha_expedicion' => $row['fecha_expedicion'],
                'cantidad' => 1,
            ];
        }

        return $entries;
    }

    /**
     * Lee el Excel (primera hoja) y devuelve las filas válidas, los errores por fila y si se usó el formato antiguo.
     *
     * @return array{rows: list<array{documento: string, nombre: ?string, hospital: ?string, fecha_expedicion: ?string, beneficiario: ?string, parentesco: ?string, edad: ?string}>, errors: list<string>, legacy: bool, ignored_columns: list<string>}
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
            if (isset($candidate['documento'])) {
                $headerIndex = $index;
                $mapping = $candidate;
                $ignored = $candidateIgnored;
                break;
            }
        }

        $legacy = $headerIndex === null;
        if ($legacy) {
            $mapping = self::LEGACY_COLUMNS;
            $dataRows = array_slice($rows, 1, null, true);
        } else {
            $dataRows = array_slice($rows, $headerIndex + 1, null, true);
        }

        if (count($dataRows) > self::MAX_ROWS) {
            return [
                'rows' => [],
                'errors' => ['El listado supera el máximo de '.self::MAX_ROWS.' filas.'],
                'legacy' => $legacy,
                'ignored_columns' => $ignored,
            ];
        }

        $entries = [];
        $errors = [];
        foreach ($dataRows as $index => $row) {
            $excelRow = $index + 1;
            $value = fn (string $field): ?string => isset($mapping[$field]) ? $this->cellToString($row[$mapping[$field]] ?? null) : null;

            $documentoRaw = $value('documento');
            $nombre = $value('nombre');
            $beneficiario = $value('beneficiario');
            $fechaRaw = $value('fecha_expedicion');

            if ($documentoRaw === null && $nombre === null && $beneficiario === null && $fechaRaw === null) {
                continue;
            }

            if ($legacy && $documentoRaw !== null && $this->looksLikeHeader($documentoRaw)) {
                continue;
            }

            $documento = $documentoRaw !== null ? $this->normalizeDocumento($documentoRaw) : '';
            if ($documento === '') {
                $errors[] = "Fila {$excelRow}: falta el documento.";

                continue;
            }

            if ($legacy && $fechaRaw === null) {
                $errors[] = "Fila {$excelRow}: falta la fecha de expedición (obligatoria en el formato sin encabezados).";

                continue;
            }

            $fecha = null;
            if ($fechaRaw !== null) {
                $fecha = $this->parseFecha($fechaRaw);
                if ($fecha === null) {
                    $errors[] = "Fila {$excelRow}: la fecha de expedición «{$fechaRaw}» no es válida (use dd/mm/aaaa).";

                    continue;
                }
            }

            $entries[] = [
                'documento' => $documento,
                'nombre' => $nombre !== null ? Str::limit($nombre, 200, '') : null,
                'hospital' => ($hospital = $value('hospital')) !== null ? Str::limit($hospital, 150, '') : null,
                'fecha_expedicion' => $fecha,
                'beneficiario' => $beneficiario !== null ? Str::limit($beneficiario, 200, '') : null,
                'parentesco' => ($parentesco = $value('parentesco')) !== null ? Str::limit($parentesco, 50, '') : null,
                'edad' => ($edad = $value('edad')) !== null ? Str::limit($edad, 10, '') : null,
            ];
        }

        if ($entries === [] && $errors === []) {
            $errors[] = $legacy
                ? 'No se encontró la columna "DOCUMENTO" ni filas con datos. Use la plantilla descargable.'
                : 'El listado no tiene filas con datos.';
        }

        if (count($errors) > self::MAX_ERRORS) {
            $total = count($errors);
            $errors = array_slice($errors, 0, self::MAX_ERRORS);
            $errors[] = 'Y '.($total - self::MAX_ERRORS).' error(es) más.';
        }

        return ['rows' => $entries, 'errors' => $errors, 'legacy' => $legacy, 'ignored_columns' => $ignored];
    }

    /**
     * Resumen de un listado ya leído.
     *
     * @param  list<array{documento: string}>  $rows
     * @return array{filas: int, personas: int}
     */
    public function summarize(array $rows): array
    {
        return [
            'filas' => count($rows),
            'personas' => count(array_unique(array_column($rows, 'documento'))),
        ];
    }

    /**
     * Genera la plantilla estándar (hojas "Listado" e "Instructivo") y devuelve la ruta del archivo temporal.
     */
    public function buildTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listado');

        $col = 1;
        foreach (self::TEMPLATE_HEADERS as $field => $label) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($letter.'1', $field === 'documento' ? $label.' *' : $label);
            $sheet->getColumnDimension($letter)->setWidth(in_array($field, ['nombre', 'beneficiario'], true) ? 38 : 20);
            $col++;
        }
        $lastLetter = Coordinate::stringFromColumnIndex(count(self::TEMPLATE_HEADERS));
        $headerStyle = $sheet->getStyle('A1:'.$lastLetter.'1');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F4E78');
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');

        $help = $spreadsheet->createSheet();
        $help->setTitle('Instructivo');
        $headers = array_values(self::TEMPLATE_HEADERS);
        $lines = [
            ['INSTRUCTIVO DEL LISTADO PARA CAMPAÑAS CON INSCRIPCIÓN'],
            [''],
            ['Este listado define quiénes pueden inscribirse en las campañas en modo "Inscripción con listado".'],
            ['Sirve para cualquier tipo de campaña: kits con beneficiarios, invitados a una fiesta, cupos para un evento, etc.'],
            [''],
            ['1. Diligencie la hoja "Listado". La fila 1 son los encabezados; los datos empiezan en la fila 2. No cambie el nombre de los encabezados.'],
            ['2. DOCUMENTO es la única columna obligatoria: solo el número, sin puntos ni espacios.'],
            ['3. Las demás columnas son opcionales. Puede dejarlas vacías o eliminarlas si la campaña no las necesita.'],
            ['4. FECHA EXPEDICION (dd/mm/aaaa): si se diligencia, la persona debe ingresar esa misma fecha para inscribirse.'],
            ['   Si se deja vacía, la persona se valida solo con su número de documento.'],
            ['5. Beneficiarios: una fila por cada beneficiario, repitiendo el documento (ej. un afiliado con 3 hijos = 3 filas).'],
            ['   Si la campaña es solo para la persona (ej. una fiesta), use una fila por persona y deje vacío NOMBRE BENEFICIARIO.'],
            ['6. NOMBRE, HOSPITAL, PARENTESCO y EDAD se muestran a la persona al inscribirse.'],
            ['7. Al cargar un nuevo archivo se reemplaza el anterior. Las inscripciones ya registradas se conservan.'],
            ['8. Formatos aceptados: .xlsx o .xls, máximo 10 MB y '.number_format(self::MAX_ROWS, 0, ',', '.').' filas.'],
            [''],
            ['EJEMPLO 1: KIT CON BENEFICIARIOS'],
            $headers,
            ['4053599', 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', '15/03/1990', 'ROSSYEL NOGUERA', 'HIJO', '5'],
            ['4053599', 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', '15/03/1990', 'ROSSYO NOGUERA', 'HIJO', '3'],
            [''],
            ['EJEMPLO 2: LISTADO DE INVITADOS (SOLO LA PERSONA)'],
            $headers,
            ['8103936', 'GIRALDO MORENO JUAN CAMILO', 'HLM - GRUPO 1', '', '', '', ''],
            ['1020304050', 'PEREZ GOMEZ MARIA FERNANDA', 'HPTU', '', '', '', ''],
        ];
        foreach ($lines as $index => $values) {
            foreach ($values as $colIndex => $value) {
                $help->setCellValueExplicit(Coordinate::stringFromColumnIndex($colIndex + 1).($index + 1), $value, DataType::TYPE_STRING);
            }
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        foreach ([17, 18, 22, 23] as $boldRow) {
            $help->getStyle('A'.$boldRow.':G'.$boldRow)->getFont()->setBold(true);
        }
        $help->getColumnDimension('A')->setWidth(22);
        foreach (['B', 'E'] as $letter) {
            $help->getColumnDimension($letter)->setWidth(32);
        }
        foreach (['C', 'D', 'F', 'G'] as $letter) {
            $help->getColumnDimension($letter)->setWidth(18);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'plantilla_inscripcion_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Check if the Excel file exists and is readable.
     * First checks S3 for active version, then falls back to local file.
     */
    public function isFileAvailable(): bool
    {
        $activeVersion = KitBienestarFileVersion::getActiveVersion();

        if ($activeVersion) {
            return Storage::disk('prosalud-private')->exists($activeVersion->s3_path);
        }

        $filePath = base_path(self::EXCEL_FILE_PATH);

        return file_exists($filePath) && is_readable($filePath);
    }

    /**
     * Deja solo letras y números del documento (quita puntos, comas, espacios y guiones).
     */
    public function normalizeDocumento(string $documento): string
    {
        return mb_strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $documento) ?? '');
    }

    /**
     * Arma la respuesta de autenticación. Conserva el formato de un solo beneficiario cuando aplica;
     * en otro caso devuelve la persona con la lista de beneficiarios (vacía si la campaña es solo para la persona).
     *
     * @param  non-empty-list<array{documento: string, nombre: ?string, hospital: ?string, fecha_expedicion: ?string, beneficiario: ?string, parentesco: ?string, edad: ?string}>  $matches
     * @return array<string, mixed>
     */
    private function buildAuthPayload(array $matches): array
    {
        $first = $matches[0];
        $nombre = collect($matches)->pluck('nombre')->filter()->first();
        $hospital = collect($matches)->pluck('hospital')->filter()->first();

        if (count($matches) === 1 && $first['beneficiario'] !== null) {
            return [
                'cc' => $first['documento'],
                'nombre' => $nombre,
                'hospital' => $hospital,
                'fecha_expedicion' => $first['fecha_expedicion'],
                'beneficiario' => $first['beneficiario'],
                'parentesco' => $first['parentesco'],
                'edad' => $first['edad'],
            ];
        }

        return [
            'afiliado' => [
                'cc' => $first['documento'],
                'nombre' => $nombre,
                'hospital' => $hospital,
                'fecha_expedicion' => $first['fecha_expedicion'],
            ],
            'beneficiarios' => collect($matches)
                ->filter(fn (array $row) => $row['beneficiario'] !== null)
                ->map(fn (array $row) => [
                    'beneficiario' => $row['beneficiario'],
                    'parentesco' => $row['parentesco'],
                    'edad' => $row['edad'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Execute a callback with the Excel file path.
     * First tries to get active version from S3, then falls back to local file.
     *
     * @template T
     *
     * @param  callable(string):T  $callback
     * @param  T|null  $default
     * @return T|null
     */
    private function withExcelFile(callable $callback, $default = null)
    {
        $activeVersion = KitBienestarFileVersion::getActiveVersion();

        if ($activeVersion) {
            $disk = Storage::disk('prosalud-private');

            if ($disk->exists($activeVersion->s3_path)) {
                $tempFilePath = $this->downloadFileToTemp($activeVersion);

                if ($tempFilePath && file_exists($tempFilePath)) {
                    try {
                        return $callback($tempFilePath);
                    } catch (\Throwable $e) {
                        Log::error('Error al procesar el listado de inscripción desde S3', [
                            'error' => $e->getMessage(),
                            's3_path' => $activeVersion->s3_path,
                            'file_version_id' => $activeVersion->id,
                        ]);

                        return $default;
                    } finally {
                        @unlink($tempFilePath);
                    }
                }
            }
        }

        $filePath = base_path(self::EXCEL_FILE_PATH);

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            Log::error('Listado de inscripción no encontrado o no es legible', [
                'file_path' => $filePath,
                'has_active_version' => $activeVersion !== null,
            ]);

            return $default;
        }

        try {
            return $callback($filePath);
        } catch (\Throwable $e) {
            Log::error('Error al procesar el listado de inscripción', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
            ]);

            return $default;
        }
    }

    /**
     * Download file from S3 to temporary location, keeping its extension so the right reader is used.
     */
    private function downloadFileToTemp(KitBienestarFileVersion $version): ?string
    {
        try {
            $fileContent = Storage::disk('prosalud-private')->get($version->s3_path);

            if ($fileContent === null) {
                Log::error('No se pudo descargar archivo desde S3', [
                    's3_path' => $version->s3_path,
                ]);

                return null;
            }

            $extension = strtolower(pathinfo($version->s3_path, PATHINFO_EXTENSION)) === 'xls' ? 'xls' : 'xlsx';
            $tempFilePath = tempnam(sys_get_temp_dir(), 'kit_bienestar_').'.'.$extension;
            file_put_contents($tempFilePath, $fileContent);

            return $tempFilePath;
        } catch (\Throwable $e) {
            Log::error('Error al descargar archivo desde S3 a temporal', [
                'error' => $e->getMessage(),
                's3_path' => $version->s3_path,
            ]);

            return null;
        }
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
            if ($field === null) {
                foreach (self::COLUMN_KEYWORDS as $candidate => $keyword) {
                    if (in_array($keyword, explode(' ', $normalized), true)) {
                        $field = $candidate;
                        break;
                    }
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

    private function looksLikeHeader(string $value): bool
    {
        return stripos($value, 'cc') !== false
            || stripos($value, 'documento') !== false
            || stripos($value, 'número') !== false;
    }

    /**
     * Convierte la fecha (texto dd/mm/aa, dd/mm/aaaa, aaaa-mm-dd o fecha de Excel) a dd/mm/aa. Devuelve null si no es válida.
     */
    private function parseFecha(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/y');
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            $serial = (float) $text;
            if ($serial < 1 || $serial > 2958465) {
                return null;
            }

            return ExcelDate::excelToDateTimeObject($serial)->format('d/m/y');
        }

        $text = str_replace(['-', '.'], '/', $text);
        $format = match (true) {
            (bool) preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $text) => '!d/m/Y',
            (bool) preg_match('/^\d{1,2}\/\d{1,2}\/\d{2}$/', $text) => '!d/m/y',
            (bool) preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $text) => '!Y/m/d',
            default => null,
        };
        if ($format === null) {
            return null;
        }

        $date = \DateTime::createFromFormat($format, $text);
        $errors = \DateTime::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('d/m/y');
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
