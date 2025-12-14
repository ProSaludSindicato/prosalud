# Guía para Verificar Logs de Caché

## Logs Implementados

Se han agregado logs en todos los procesos con caché para verificar su funcionamiento:

### 1. **AfiliadoService - authenticateAndGetAfiliado()**

**Logs disponibles:**
- `[AFILIADO SERVICE] Iniciando autenticación de afiliado` - Al entrar al método
- `[AFILIADO SERVICE] Clave de caché generada` - Cuando se genera la clave
- `[CACHE HIT] Afiliado obtenido desde caché` - Cuando se obtiene del caché
- `[CACHE MISS] Consultando afiliado desde Excel` - Cuando NO está en caché
- `[CACHE STORED] Afiliado guardado en caché` - Cuando se guarda en caché

**Nivel:** `Log::info()` y `Log::debug()`

### 2. **CheckPermission Middleware**

**Logs disponibles:**
- `[CACHE HIT] Permisos obtenidos desde caché`
- `[CACHE MISS] Consultando permisos desde BD`
- `[CACHE STORED] Permisos guardados en caché`

**Nivel:** `Log::debug()`

### 3. **AuthenticateWithApiToken Middleware**

**Logs disponibles:**
- `[CACHE HIT] Permisos obtenidos desde caché (API Token)`
- `[CACHE MISS] Consultando permisos desde BD (API Token)`
- `[CACHE STORED] Permisos guardados en caché (API Token)`

**Nivel:** `Log::debug()`

### 4. **RequestAssignmentService - getUserAssignments()**

**Logs disponibles:**
- `[CACHE HIT] Asignaciones obtenidas desde caché`
- `[CACHE MISS] Consultando asignaciones desde BD`
- `[CACHE STORED] Asignaciones guardadas en caché`

**Nivel:** `Log::debug()`

### 5. **ExcelReaderService - readActivosFile()**

**Logs disponibles:**
- `[CACHE HIT] Archivo ACTIVOS obtenido desde caché`
- `[CACHE MISS] Leyendo archivo ACTIVOS desde disco`
- `[CACHE STORED] Archivo ACTIVOS guardado en caché`

**Nivel:** `Log::info()`

### 6. **InventoryDashboardController - index()**

**Logs disponibles:**
- `[CACHE HIT] Dashboard de inventario obtenido desde caché`
- `[CACHE MISS] Calculando métricas del dashboard desde BD`
- `[CACHE STORED] Dashboard de inventario guardado en caché`

**Nivel:** `Log::info()`

---

## Cómo Ver los Logs

### Opción 1: Ver logs en tiempo real (recomendado)

```bash
# Ver todos los logs de caché
tail -f storage/logs/laravel.log | grep "CACHE\|AFILIADO SERVICE"

# Ver solo hits de caché
tail -f storage/logs/laravel.log | grep "CACHE HIT"

# Ver solo misses de caché
tail -f storage/logs/laravel.log | grep "CACHE MISS"

# Ver logs de afiliados específicamente
tail -f storage/logs/laravel.log | grep "AFILIADO SERVICE\|afiliado"
```

### Opción 2: Ver últimos logs

```bash
# Últimas 50 líneas con logs de caché
tail -n 50 storage/logs/laravel.log | grep "CACHE\|AFILIADO SERVICE"

# Últimas 100 líneas
tail -n 100 storage/logs/laravel.log | grep "CACHE\|AFILIADO SERVICE"
```

### Opción 3: Buscar logs específicos

```bash
# Buscar logs de un documento específico
grep "documento.*12345678" storage/logs/laravel.log

# Buscar logs de autenticación de afiliados
grep "Iniciando autenticación de afiliado" storage/logs/laravel.log
```

---

## Verificar que los Logs Funcionan

### 1. Verificar nivel de log

Asegúrate de que en tu `.env` tengas:
```env
LOG_LEVEL=debug
```

O al menos:
```env
LOG_LEVEL=info
```

### 2. Probar con una autenticación

Haz una petición POST a:
```
POST /api/afiliados/authenticate
```

Con el body:
```json
{
  "tipo_documento": "CC",
  "documento": "12345678",
  "fecha_expedicion": "2020-01-01"
}
```

### 3. Verificar los logs inmediatamente

```bash
tail -n 20 storage/logs/laravel.log
```

Deberías ver algo como:
```
[2025-12-14 12:00:00] local.INFO: [AFILIADO SERVICE] Iniciando autenticación de afiliado {"documento":"12345678","tipo_documento":"CC","fecha_expedicion":"2020-01-01"}
[2025-12-14 12:00:00] local.DEBUG: [AFILIADO SERVICE] Clave de caché generada {"cache_key":"afiliado:auth:abc123:def456:ghi789","documento":"12345678"}
[2025-12-14 12:00:00] local.INFO: [CACHE MISS] Consultando afiliado desde Excel {"cache_key":"afiliado:auth:abc123:def456:ghi789","documento":"12345678","tipo_documento":"CC"}
[2025-12-14 12:00:05] local.INFO: [CACHE STORED] Afiliado guardado en caché {"cache_key":"afiliado:auth:abc123:def456:ghi789","documento":"12345678","tipo_documento":"CC","ttl_hours":24}
```

### 4. Segunda consulta (debería ser CACHE HIT)

Haz la misma petición nuevamente y deberías ver:
```
[2025-12-14 12:01:00] local.INFO: [AFILIADO SERVICE] Iniciando autenticación de afiliado {"documento":"12345678","tipo_documento":"CC","fecha_expedicion":"2020-01-01"}
[2025-12-14 12:01:00] local.DEBUG: [AFILIADO SERVICE] Clave de caché generada {"cache_key":"afiliado:auth:abc123:def456:ghi789","documento":"12345678"}
[2025-12-14 12:01:00] local.INFO: [CACHE HIT] Afiliado obtenido desde caché {"cache_key":"afiliado:auth:abc123:def456:ghi789","documento":"12345678","tipo_documento":"CC"}
```

---

## Solución de Problemas

### No veo ningún log

1. **Verificar permisos del archivo de log:**
   ```bash
   ls -la storage/logs/laravel.log
   chmod 666 storage/logs/laravel.log  # Si es necesario
   ```

2. **Verificar que el archivo existe:**
   ```bash
   touch storage/logs/laravel.log
   chmod 666 storage/logs/laravel.log
   ```

3. **Verificar configuración de logs:**
   ```bash
   php artisan tinker --execute="Log::info('Test'); echo 'OK';"
   tail -n 1 storage/logs/laravel.log
   ```

4. **Limpiar caché de configuración:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

### Solo veo algunos logs

- Los logs con nivel `debug` solo aparecen si `LOG_LEVEL=debug` en `.env`
- Los logs con nivel `info` aparecen con `LOG_LEVEL=info` o superior

### Los logs aparecen pero no veo CACHE HIT/MISS

1. Verifica que Redis esté funcionando:
   ```bash
   redis-cli ping
   ```

2. Verifica la configuración de caché:
   ```bash
   php artisan tinker --execute="echo config('cache.default');"
   ```

3. Verifica que el método se esté llamando:
   - Busca el log `[AFILIADO SERVICE] Iniciando autenticación de afiliado`
   - Si no aparece, el método no se está ejecutando

---

## Ejemplo de Flujo Completo

### Primera autenticación (CACHE MISS):

```
[2025-12-14 12:00:00] local.INFO: Intento de autenticación de afiliado {"tipo_documento":"CC","documento":"12345678",...}
[2025-12-14 12:00:00] local.INFO: [AFILIADO SERVICE] Iniciando autenticación de afiliado {"documento":"12345678",...}
[2025-12-14 12:00:00] local.DEBUG: [AFILIADO SERVICE] Clave de caché generada {"cache_key":"afiliado:auth:...",...}
[2025-12-14 12:00:00] local.INFO: [CACHE MISS] Consultando afiliado desde Excel {"cache_key":"afiliado:auth:...",...}
[2025-12-14 12:00:05] local.INFO: [CACHE STORED] Afiliado guardado en caché {"cache_key":"afiliado:auth:...",...}
```

### Segunda autenticación (CACHE HIT):

```
[2025-12-14 12:01:00] local.INFO: Intento de autenticación de afiliado {"tipo_documento":"CC","documento":"12345678",...}
[2025-12-14 12:01:00] local.INFO: [AFILIADO SERVICE] Iniciando autenticación de afiliado {"documento":"12345678",...}
[2025-12-14 12:01:00] local.DEBUG: [AFILIADO SERVICE] Clave de caché generada {"cache_key":"afiliado:auth:...",...}
[2025-12-14 12:01:00] local.INFO: [CACHE HIT] Afiliado obtenido desde caché {"cache_key":"afiliado:auth:...",...}
```

---

## Comandos Útiles

```bash
# Ver tamaño del archivo de log
ls -lh storage/logs/laravel.log

# Contar cuántos CACHE HIT hay
grep -c "CACHE HIT" storage/logs/laravel.log

# Contar cuántos CACHE MISS hay
grep -c "CACHE MISS" storage/logs/laravel.log

# Ver estadísticas de caché
echo "Hits: $(grep -c 'CACHE HIT' storage/logs/laravel.log)"
echo "Misses: $(grep -c 'CACHE MISS' storage/logs/laravel.log)"

# Limpiar logs antiguos (opcional)
> storage/logs/laravel.log
```

