# Integración CloudConvert - Office to PDF

Esta documentación describe la integración con CloudConvert API para convertir archivos Word (.docx) a PDF.

## Configuración

### 1. Variables de Entorno

Agrega la siguiente variable en tu archivo `.env`:

```env
CLOUDCONVERT_API_KEY=tu_api_key_aqui
CLOUDCONVERT_TIMEOUT=60
CLOUDCONVERT_MAX_FILE_SIZE=26214400
```

**Nota:** 
- `CLOUDCONVERT_API_KEY`: Obtén tu API key desde [CloudConvert Dashboard](https://cloudconvert.com/dashboard/api-keys)
- `CLOUDCONVERT_TIMEOUT`: Tiempo máximo de espera en segundos (por defecto: 60)
- `CLOUDCONVERT_MAX_FILE_SIZE`: Tamaño máximo de archivo en bytes (por defecto: 25 MB)

### 2. Archivo de Configuración

La configuración se encuentra en `config/cloudconvert.php`:

```php
return [
    'api_key' => env('CLOUDCONVERT_API_KEY', ''),
    'timeout' => env('CLOUDCONVERT_TIMEOUT', 60),
    'max_file_size' => env('CLOUDCONVERT_MAX_FILE_SIZE', 25 * 1024 * 1024),
    'temp_storage_path' => storage_path('app/tmp'),
];
```

## Uso del Servicio

### Método Directo

```php
use App\Services\DocxToPdfCloudConvertService;

$converterService = app(DocxToPdfCloudConvertService::class);

// Convertir y guardar en storage
$result = $converterService->convert('/ruta/al/archivo.docx', true);
// Retorna: ['path' => 'tmp/archivo_1234567890.pdf', 'content' => null, 'size' => 12345]

// Convertir y obtener contenido binario
$result = $converterService->convert('/ruta/al/archivo.docx', false);
// Retorna: ['content' => 'contenido_binario_pdf', 'size' => 12345]
```

### Usando el Facade

```php
use App\Facades\DocxToPdf;

// Convertir y guardar en storage
$result = DocxToPdf::convert('/ruta/al/archivo.docx', true);

// Convertir y obtener contenido binario
$result = DocxToPdf::convert('/ruta/al/archivo.docx', false);
```

### Desde el Controlador

```php
use App\Http\Controllers\ConvertDocumentController;

// POST /api/convert/docx-to-pdf
// Body: { "file_path": "tmp/documento.docx", "download": false }
```

## Flujo del Job CloudConvert

El servicio sigue este flujo paso a paso:

### 1. Import/Upload del archivo DOCX

```php
new Task('import/upload', 'upload-file')
    ->set('file', fopen($docxPath, 'r'))
    ->set('filename', basename($docxPath))
```

- Sube el archivo .docx a CloudConvert
- CloudConvert almacena temporalmente el archivo

### 2. Conversión DOCX → PDF

```php
new Task('convert', 'convert-docx-to-pdf')
    ->set('input', 'upload-file')
    ->set('input_format', 'docx')
    ->set('output_format', 'pdf')
```

- CloudConvert convierte el archivo Word a PDF
- Preserva formato, imágenes y estilos

### 3. Export/URL para obtener el PDF

```php
new Task('export/url', 'export-pdf')
    ->set('input', 'convert-docx-to-pdf')
```

- Genera una URL temporal para descargar el PDF
- La URL expira después de un tiempo determinado

### 4. Descarga del PDF

```php
$pdfContent = file_get_contents($pdfUrl);
```

- Descarga el contenido binario del PDF
- Valida que sea un PDF válido (verifica header `%PDF`)

### 5. Almacenamiento (opcional)

```php
file_put_contents($pdfPath, $pdfContent);
```

- Guarda el PDF en `storage/app/tmp/`
- Retorna la ruta relativa para acceso posterior

## Manejo de Errores

### Errores Comunes

#### 1. API Key no configurada

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "CLOUDCONVERT_API_KEY no está configurada en .env"
}
```

**Solución:** Agrega `CLOUDCONVERT_API_KEY` en tu archivo `.env`

#### 2. Archivo no encontrado

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "El archivo .docx no existe en: /ruta/al/archivo.docx"
}
```

**Solución:** Verifica que la ruta del archivo sea correcta

#### 3. Archivo excede tamaño máximo

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "El archivo excede el tamaño máximo permitido. Tamaño: 30.5 MB, Máximo: 25 MB"
}
```

**Solución:** 
- Reduce el tamaño del archivo
- O aumenta `CLOUDCONVERT_MAX_FILE_SIZE` en `.env` (requiere plan Pro de CloudConvert)

#### 4. Timeout en conversión

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "Timeout esperando la conversión. Tiempo máximo: 60s"
}
```

**Solución:**
- Aumenta `CLOUDCONVERT_TIMEOUT` en `.env`
- Verifica que el archivo no esté corrupto
- Archivos muy grandes pueden tardar más

#### 5. Error en CloudConvert

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "Error en CloudConvert: Invalid file format"
}
```

**Solución:**
- Verifica que el archivo sea un .docx válido
- Revisa los logs de CloudConvert en su dashboard
- Algunos formatos pueden no ser compatibles

### Validaciones Implementadas

1. **Existencia del archivo:** Verifica que el archivo exista antes de procesarlo
2. **Tamaño del archivo:** Valida que no exceda el límite configurado
3. **Extensión:** Solo acepta archivos `.docx`
4. **Formato PDF:** Valida que el archivo descargado sea un PDF válido
5. **Timeout:** Controla el tiempo máximo de espera

## Ejemplo de Respuesta JSON

### Conversión exitosa (guardada en storage)

```json
{
    "success": true,
    "message": "Archivo convertido exitosamente",
    "pdf_path": "tmp/certificado_1703123456.pdf",
    "size": 245678
}
```

### Conversión exitosa (descarga directa)

El endpoint retorna el PDF como binario con headers:

```
Content-Type: application/pdf
Content-Disposition: attachment; filename="certificado.pdf"
Content-Length: 245678
```

### Error en conversión

```json
{
    "success": false,
    "message": "Error al convertir el archivo",
    "error": "Error en CloudConvert: Invalid file format"
}
```

## Integración con CertificadoConvenioService

El servicio de certificados usa automáticamente CloudConvert para generar PDFs:

```php
// En CertificadoConvenioService::generarCertificadoPDF()
$resultadoWord = $this->generarCertificadoWord($documento);
$converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
$resultadoPDF = $converterService->convert($resultadoWord['ruta'], true);
```

## Rutas API

### POST /api/convert/docx-to-pdf

Convierte un archivo .docx a PDF.

**Request:**
```json
{
    "file_path": "tmp/documento.docx",
    "download": false
}
```

**Response (guardado en storage):**
```json
{
    "success": true,
    "message": "Archivo convertido exitosamente",
    "pdf_path": "tmp/documento_1703123456.pdf",
    "size": 245678
}
```

**Response (descarga directa):**
Retorna el PDF como binario con headers apropiados.

### GET /api/convert/test

Verifica la configuración de CloudConvert.

**Response:**
```json
{
    "success": true,
    "message": "Configuración de CloudConvert verificada",
    "config": {
        "api_key_configured": true,
        "timeout": 60,
        "max_file_size": 26214400,
        "temp_storage_path": "/path/to/storage/app/tmp"
    }
}
```

## Límites y Consideraciones

### Límites de CloudConvert

- **Plan Free:** 25 MB por archivo, 25 conversiones/día
- **Plan Pro:** 1 GB por archivo, conversiones ilimitadas

### Consideraciones de Rendimiento

- La conversión puede tardar entre 10-30 segundos dependiendo del tamaño
- Se recomienda usar colas (queues) para archivos grandes
- Los archivos se almacenan temporalmente en `storage/app/tmp/`

### Limpieza de Archivos Temporales

Los archivos PDF generados se almacenan en `storage/app/tmp/`. Considera implementar un job periódico para limpiar archivos antiguos:

```php
// Ejemplo de limpieza (implementar en un Scheduled Task)
$files = Storage::files('tmp');
foreach ($files as $file) {
    $lastModified = Storage::lastModified($file);
    if (now()->timestamp - $lastModified > 3600) { // 1 hora
        Storage::delete($file);
    }
}
```

## Troubleshooting

### El PDF no se genera correctamente

1. Verifica que el archivo .docx sea válido
2. Revisa los logs de Laravel: `storage/logs/laravel.log`
3. Verifica la API key en CloudConvert Dashboard
4. Comprueba que no haya límites de cuota alcanzados

### La conversión tarda mucho

1. Verifica el tamaño del archivo
2. Aumenta el timeout en `.env`
3. Considera usar colas para procesamiento asíncrono

### Error de autenticación

1. Verifica que `CLOUDCONVERT_API_KEY` esté correctamente configurada
2. Asegúrate de que la API key sea válida en CloudConvert Dashboard
3. Verifica que no haya espacios extra en el `.env`

