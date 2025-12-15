<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ShowCacheInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:info 
                            {--type= : Filtrar por tipo (afiliado, permissions, assignments, excel, inventory, events)}
                            {--key= : Mostrar información de una clave específica}
                            {--stats : Mostrar solo estadísticas}
                            {--clear : Limpiar caché después de mostrar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Muestra información sobre el contenido del caché de Redis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $key = $this->option('key');
        $stats = $this->option('stats');
        $clear = $this->option('clear');

        try {
            // Obtener prefijo de caché
            $cachePrefix = config('cache.prefix', '');
            
            // Obtener prefijo de la conexión Redis de caché
            $connection = config('cache.stores.redis.connection', 'cache');
            $redisPrefix = config("database.redis.{$connection}.prefix", '');
            
            // Laravel combina ambos prefijos: redis prefix + cache prefix
            // Pero en la práctica, Redis usa su propio prefijo para todas las operaciones
            // Intentar detectar el prefijo real buscando claves conocidas
            $prefix = $this->detectActualPrefix($connection, $cachePrefix, $redisPrefix);
            
            // Mostrar información de configuración en modo verbose
            if ($this->option('verbose')) {
                $this->line("🔧 Configuración:");
                $this->line("   Prefijo Cache: {$cachePrefix}");
                $this->line("   Prefijo Redis: {$redisPrefix}");
                $this->line("   Prefijo usado: {$prefix}");
                $this->line("   Conexión: {$connection}");
                $this->line("   Driver: " . config('cache.default', 'redis'));
                $this->line('');
            }
            
            if ($key) {
                $this->showKeyInfo($key, $prefix);
                return 0;
            }

            if ($stats) {
                $this->showStats($prefix, $type);
                return 0;
            }

            // Mostrar información detallada
            $this->showCacheDetails($prefix, $type);

            if ($clear) {
                if ($this->confirm('¿Estás seguro de que quieres limpiar el caché?', false)) {
                    Cache::flush();
                    $this->info('✅ Caché limpiado exitosamente');
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error al acceder al caché: ' . $e->getMessage());
            $this->warn('Asegúrate de que Redis esté corriendo y configurado correctamente.');
            return 1;
        }
    }

    /**
     * Mostrar información detallada del caché
     */
    private function showCacheDetails(string $prefix, ?string $type): void
    {
        $this->info('📦 Información del Caché Redis');
        $this->line('');

        // Si no hay tipo específico, también mostrar todas las claves
        if ($type === null) {
            $allCacheKeys = $this->getAllCacheKeys($prefix);
            if (!empty($allCacheKeys)) {
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->info("📋 Todas las Claves de Caché (" . count($allCacheKeys) . " claves)");
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                
                $tableData = [];
                foreach ($allCacheKeys as $fullKey) {
                    $key = str_replace($prefix, '', $fullKey);
                    $ttl = $this->getKeyTTL($fullKey);
                    $size = $this->getKeySize($fullKey);
                    $value = $this->getKeyPreview($fullKey);

                    $tableData[] = [
                        'Clave' => $this->truncate($key, 50),
                        'TTL' => $ttl > 0 ? $this->formatTTL($ttl) : 'Sin expiración',
                        'Tamaño' => $this->formatBytes($size),
                        'Vista previa' => $this->truncate($value, 30),
                    ];
                }

                $this->table(['Clave', 'TTL', 'Tamaño', 'Vista previa'], $tableData);
                $this->line('');
            }
        }

        $patterns = $this->getCachePatterns($type);
        $allKeys = [];

        foreach ($patterns as $pattern => $label) {
            $keys = $this->getKeysByPattern($prefix . $pattern);
            if (!empty($keys)) {
                $allKeys[$label] = $keys;
            }
        }

        if (empty($allKeys) && ($type !== null || empty($allCacheKeys ?? []))) {
            $this->warn('No se encontraron claves en el caché con los patrones especificados.');
            $this->line('');
            $this->comment('💡 Tip: Usa --type para filtrar por tipo específico:');
            $this->line('   php artisan cache:info --type=afiliado');
            $this->line('   php artisan cache:info --type=permissions');
            $this->line('   php artisan cache:info --type=assignments');
            $this->line('   php artisan cache:info --type=events');
            return;
        }

        $totalSize = 0;
        $totalKeys = 0;

        foreach ($allKeys as $label => $keys) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📋 {$label} (" . count($keys) . " claves)");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

            $tableData = [];
            foreach ($keys as $fullKey) {
                $key = str_replace($prefix, '', $fullKey);
                $ttl = $this->getKeyTTL($fullKey);
                $size = $this->getKeySize($fullKey);
                $value = $this->getKeyPreview($fullKey);

                $totalSize += $size;
                $totalKeys++;

                $tableData[] = [
                    'Clave' => $this->truncate($key, 50),
                    'TTL' => $ttl > 0 ? $this->formatTTL($ttl) : 'Sin expiración',
                    'Tamaño' => $this->formatBytes($size),
                    'Vista previa' => $this->truncate($value, 30),
                ];
            }

            $this->table(['Clave', 'TTL', 'Tamaño', 'Vista previa'], $tableData);
            $this->line('');
        }

        // Mostrar estadísticas generales
        $this->showGeneralStats($totalKeys, $totalSize);
    }

    /**
     * Detectar el prefijo real usado por Redis
     */
    private function detectActualPrefix(string $connection, string $cachePrefix, string $redisPrefix): string
    {
        try {
            $redis = Redis::connection($connection);
            
            // Buscar todas las claves para detectar el prefijo real
            $allKeys = $redis->keys('*');
            
            if (empty($allKeys) || !is_array($allKeys)) {
                // Combinar prefijos: Redis puede usar ambos
                return ($redisPrefix ?: '') . ($cachePrefix ?: '');
            }
            
            // Buscar una clave que contenga patrones conocidos de caché
            foreach ($allKeys as $key) {
                foreach (['afiliado:auth:', 'user:', 'excel:', 'inventory:', 'afiliado_service.', 'comfenalco_events:'] as $pattern) {
                    $pos = strpos($key, $pattern);
                    if ($pos !== false) {
                        return substr($key, 0, $pos);
                    }
                }
            }
            
            // Si no encontramos patrones, combinar prefijos
            return ($redisPrefix ?: '') . ($cachePrefix ?: '');
        } catch (\Exception $e) {
            return ($redisPrefix ?: '') . ($cachePrefix ?: '');
        }
    }

    /**
     * Obtener todas las claves del caché
     */
    private function getAllCacheKeys(string $prefix): array
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            
            // Buscar todas las claves (con o sin prefijo)
            $pattern = '*';
            
            // Intentar usar SCAN primero (más eficiente para producción)
            try {
                $keys = [];
                $cursor = 0;
                
                do {
                    $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
                    if (is_array($result) && count($result) >= 2) {
                        $cursor = $result[0];
                        $keys = array_merge($keys, $result[1]);
                    } elseif (is_array($result) && count($result) === 1) {
                        // Algunas versiones de Redis devuelven solo el array de claves
                        $keys = array_merge($keys, $result);
                        break;
                    } else {
                        break;
                    }
                } while ($cursor !== 0 && $cursor !== '0' && count($keys) < 1000);

                if (!empty($keys)) {
                    // Filtrar solo las claves que pertenecen al caché de la aplicación
                    $filteredKeys = array_filter($keys, function($key) use ($prefix) {
                        // Si hay prefijo, verificar que la clave lo tenga
                        if ($prefix) {
                            return strpos($key, $prefix) === 0;
                        }
                        // Si no hay prefijo, buscar patrones conocidos de caché
                        return $this->isCacheKey($key);
                    });
                    return array_unique($filteredKeys);
                }
            } catch (\Exception $e) {
                // Si SCAN falla, usar KEYS como fallback
                $this->warn("SCAN no disponible, usando KEYS: " . $e->getMessage());
            }
            
            // Fallback: usar KEYS (solo para desarrollo, puede ser lento en producción)
            $allKeys = $redis->keys($pattern);
            if (is_array($allKeys)) {
                // Filtrar solo las claves que pertenecen al caché
                $filteredKeys = array_filter($allKeys, function($key) use ($prefix) {
                    if ($prefix) {
                        return strpos($key, $prefix) === 0;
                    }
                    return $this->isCacheKey($key);
                });
                return array_unique($filteredKeys);
            }
            
            return [];
            
        } catch (\Exception $e) {
            $this->warn("Error al obtener todas las claves: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verificar si una clave pertenece al caché de la aplicación
     */
    private function isCacheKey(string $key): bool
    {
        $cachePatterns = [
            'afiliado:auth:',
            'afiliado_service.',
            'user:',
            'excel:',
            'inventory:',
            'comfenalco_events:',
        ];
        
        foreach ($cachePatterns as $pattern) {
            if (strpos($key, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Mostrar estadísticas generales
     */
    private function showStats(string $prefix, ?string $type): void
    {
        $this->info('📊 Estadísticas del Caché');
        $this->line('');

        $patterns = $this->getCachePatterns($type);
        $stats = [];

        foreach ($patterns as $pattern => $label) {
            $keys = $this->getKeysByPattern($prefix . $pattern);
            $count = count($keys);
            $size = 0;
            $totalTTL = 0;
            $expiringKeys = 0;

            foreach ($keys as $key) {
                $keySize = $this->getKeySize($key);
                $size += $keySize;
                $ttl = $this->getKeyTTL($key);
                if ($ttl > 0) {
                    $totalTTL += $ttl;
                    $expiringKeys++;
                }
            }

            if ($count > 0) {
                $avgTTL = $expiringKeys > 0 ? round($totalTTL / $expiringKeys) : 0;
                $stats[] = [
                    'Tipo' => $label,
                    'Claves' => $count,
                    'Tamaño Total' => $this->formatBytes($size),
                    'Tamaño Promedio' => $this->formatBytes($size / $count),
                    'TTL Promedio' => $avgTTL > 0 ? $this->formatTTL($avgTTL) : 'N/A',
                ];
            }
        }

        if (empty($stats)) {
            $this->warn('No se encontraron claves en el caché.');
            return;
        }

        $this->table(
            ['Tipo', 'Claves', 'Tamaño Total', 'Tamaño Promedio', 'TTL Promedio'],
            $stats
        );

        // Estadísticas generales
        $totalKeys = array_sum(array_column($stats, 'Claves'));
        $totalSize = 0;
        foreach ($stats as $stat) {
            $totalSize += $this->parseBytes($stat['Tamaño Total']);
        }

        $this->line('');
        $this->info('📈 Resumen General:');
        $this->line("   Total de claves: {$totalKeys}");
        $this->line("   Tamaño total: " . $this->formatBytes($totalSize));
        $this->line("   Límite Redis: 250 MB");
        $this->line("   Uso: " . round(($totalSize / (250 * 1024 * 1024)) * 100, 2) . "%");
    }

    /**
     * Mostrar información de una clave específica
     */
    private function showKeyInfo(string $key, string $prefix): void
    {
        // Intentar con prefijo primero
        $fullKey = $prefix . $key;
        
        if (!$this->keyExists($fullKey)) {
            // Intentar sin prefijo (puede que el usuario haya proporcionado la clave completa)
            if (!$this->keyExists($key)) {
                // Buscar en todas las claves
                $allKeys = $this->getAllCacheKeys('');
                $foundKey = null;
                
                foreach ($allKeys as $cacheKey) {
                    if (strpos($cacheKey, $key) !== false || strpos($cacheKey, str_replace($prefix, '', $key)) !== false) {
                        $foundKey = $cacheKey;
                        break;
                    }
                }
                
                if ($foundKey) {
                    $fullKey = $foundKey;
                } else {
                    $this->error("La clave '{$key}' no existe en el caché.");
                    $this->line('');
                    $this->comment("💡 Claves disponibles que contienen '{$key}':");
                    $matchingKeys = array_filter($allKeys, function($k) use ($key) {
                        return stripos($k, $key) !== false;
                    });
                    if (!empty($matchingKeys)) {
                        foreach (array_slice($matchingKeys, 0, 10) as $matchKey) {
                            $this->line("   - " . str_replace($prefix, '', $matchKey));
                        }
                    }
                    return;
                }
            } else {
                $fullKey = $key;
            }
        }

        $ttl = $this->getKeyTTL($fullKey);
        $size = $this->getKeySize($fullKey);
        $value = $this->getKeyValue($fullKey);

        $this->info("🔑 Información de la clave: {$key}");
        $this->line('');
        $this->line("   Clave completa: {$fullKey}");
        $this->line("   TTL: " . ($ttl > 0 ? $this->formatTTL($ttl) : 'Sin expiración'));
        $this->line("   Tamaño: " . $this->formatBytes($size));
        $this->line('');
        $this->line("   Valor:");
        $this->line("   " . str_repeat('─', 60));
        
        if (is_array($value) || is_object($value)) {
            $this->line(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line("   " . (string) $value);
        }
    }

    /**
     * Obtener patrones de caché según el tipo
     */
    private function getCachePatterns(?string $type): array
    {
        $allPatterns = [
            'afiliado:auth:*' => 'Afiliados (Autenticación)',
            'afiliado_service.all_basic' => 'Afiliados (Listado Básico)',
            'user:*:permissions' => 'Permisos de Usuarios',
            'user:*:request_assignments' => 'Asignaciones de Solicitudes',
            'excel:activos:processed' => 'Excel ACTIVOS',
            'inventory:dashboard:metrics' => 'Dashboard de Inventario',
            'comfenalco_events:list' => 'Eventos Comfenalco',
        ];

        if ($type === null) {
            return $allPatterns;
        }

        $filtered = [];
        $typeMap = [
            'afiliado' => ['afiliado:auth:*', 'afiliado_service.all_basic'],
            'permissions' => ['user:*:permissions'],
            'assignments' => ['user:*:request_assignments'],
            'excel' => ['excel:activos:processed'],
            'inventory' => ['inventory:dashboard:metrics'],
            'events' => ['comfenalco_events:list'],
        ];

        if (isset($typeMap[$type])) {
            foreach ($typeMap[$type] as $pattern) {
                if (isset($allPatterns[$pattern])) {
                    $filtered[$pattern] = $allPatterns[$pattern];
                }
            }
        }

        return $filtered;
    }

    /**
     * Obtener claves por patrón
     */
    private function getKeysByPattern(string $pattern): array
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            
            // Obtener todas las claves y filtrar por los patrones que nos interesan
            $allKeys = $this->getAllCacheKeys('');
            $matchedKeys = [];
            
            // Normalizar el patrón: remover prefijo y wildcards para búsqueda
            $searchPattern = str_replace(['*'], [''], $pattern);
            
            foreach ($allKeys as $key) {
                // Remover prefijo de la clave para comparar
                $keyWithoutPrefix = $key;
                $cachePrefix = config('cache.prefix', '');
                $redisPrefix = config("database.redis.{$connection}.prefix", '');
                $combinedPrefix = ($redisPrefix ?: '') . ($cachePrefix ?: '');
                
                if ($combinedPrefix && strpos($key, $combinedPrefix) === 0) {
                    $keyWithoutPrefix = substr($key, strlen($combinedPrefix));
                }
                
                // Verificar si la clave (con o sin prefijo) contiene el patrón que buscamos
                $patternMatches = false;
                
                if (strpos($searchPattern, 'afiliado:auth:') !== false) {
                    $patternMatches = (strpos($key, 'afiliado:auth:') !== false || strpos($keyWithoutPrefix, 'afiliado:auth:') !== false);
                } elseif (strpos($searchPattern, 'afiliado_service.all_basic') !== false) {
                    $patternMatches = (strpos($key, 'afiliado_service.all_basic') !== false || strpos($keyWithoutPrefix, 'afiliado_service.all_basic') !== false);
                } elseif (strpos($searchPattern, 'user:') !== false && strpos($searchPattern, 'permissions') !== false) {
                    $patternMatches = (strpos($key, 'user:') !== false && strpos($key, 'permissions') !== false) ||
                                     (strpos($keyWithoutPrefix, 'user:') !== false && strpos($keyWithoutPrefix, 'permissions') !== false);
                } elseif (strpos($searchPattern, 'user:') !== false && strpos($searchPattern, 'request_assignments') !== false) {
                    $patternMatches = (strpos($key, 'user:') !== false && strpos($key, 'request_assignments') !== false) ||
                                     (strpos($keyWithoutPrefix, 'user:') !== false && strpos($keyWithoutPrefix, 'request_assignments') !== false);
                } elseif (strpos($searchPattern, 'excel:activos') !== false) {
                    $patternMatches = (strpos($key, 'excel:activos') !== false || strpos($keyWithoutPrefix, 'excel:activos') !== false);
                } elseif (strpos($searchPattern, 'inventory:dashboard') !== false) {
                    $patternMatches = (strpos($key, 'inventory:dashboard') !== false || strpos($keyWithoutPrefix, 'inventory:dashboard') !== false);
                } elseif (strpos($searchPattern, 'comfenalco_events') !== false) {
                    $patternMatches = (strpos($key, 'comfenalco_events') !== false || strpos($keyWithoutPrefix, 'comfenalco_events') !== false);
                }
                
                if ($patternMatches) {
                    $matchedKeys[] = $key;
                }
            }
            
            return array_unique($matchedKeys);
            
        } catch (\Exception $e) {
            $this->warn("Error al obtener claves con patrón '{$pattern}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verificar si una clave existe
     */
    private function keyExists(string $key): bool
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            return Cache::has(str_replace(config('cache.prefix', ''), '', $key));
        }
    }

    /**
     * Obtener TTL de una clave
     */
    private function getKeyTTL(string $key): int
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            return $redis->ttl($key);
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Obtener tamaño de una clave
     */
    private function getKeySize(string $key): int
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            
            // Intentar obtener el tamaño usando MEMORY USAGE (Redis 4.0+)
            try {
                $memory = $redis->rawCommand('MEMORY', 'USAGE', $key);
                if (is_numeric($memory)) {
                    return (int) $memory;
                }
            } catch (\Exception $e) {
                // MEMORY USAGE no disponible, usar método alternativo
            }
            
            // Método alternativo: obtener el valor y calcular tamaño
            $value = $redis->get($key);
            if ($value) {
                // Tamaño del valor serializado + overhead de Redis (aproximado)
                return strlen($value) + 100; // +100 bytes de overhead aproximado
            }
            
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Obtener vista previa del valor
     */
    private function getKeyPreview(string $key): string
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            $value = $redis->get($key);
            
            if (!$value) {
                return 'Vacío';
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) {
                    $count = count($decoded);
                    $keys = array_keys($decoded);
                    $firstKey = $keys[0] ?? '';
                    return "Array ({$count} elementos)" . ($firstKey ? " - Clave: {$firstKey}" : '');
                }
                if (is_string($decoded)) {
                    return substr($decoded, 0, 30);
                }
                return gettype($decoded) . ': ' . substr(json_encode($decoded), 0, 20);
            }

            return substr($value, 0, 50);
        } catch (\Exception $e) {
            return 'Error al leer: ' . substr($e->getMessage(), 0, 20);
        }
    }

    /**
     * Obtener valor completo de una clave
     */
    private function getKeyValue(string $key)
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            $value = $redis->get($key);
            
            if (!$value) {
                return null;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return $value;
        } catch (\Exception $e) {
            return Cache::get(str_replace(config('cache.prefix', ''), '', $key));
        }
    }

    /**
     * Formatear TTL
     */
    private function formatTTL(int $seconds): string
    {
        if ($seconds < 0) {
            return 'Sin expiración';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return "{$minutes}m";
        }

        if ($seconds < 86400) {
            $hours = round($seconds / 3600, 1);
            return "{$hours}h";
        }

        $days = round($seconds / 86400, 1);
        return "{$days}d";
    }

    /**
     * Formatear bytes
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            $kb = round($bytes / 1024, 2);
            return "{$kb} KB";
        }

        $mb = round($bytes / (1024 * 1024), 2);
        return "{$mb} MB";
    }

    /**
     * Parsear bytes desde string formateado
     */
    private function parseBytes(string $formatted): int
    {
        if (preg_match('/([\d.]+)\s*(KB|MB|GB)/i', $formatted, $matches)) {
            $value = (float) $matches[1];
            $unit = strtoupper($matches[2]);
            
            switch ($unit) {
                case 'GB':
                    return (int) ($value * 1024 * 1024 * 1024);
                case 'MB':
                    return (int) ($value * 1024 * 1024);
                case 'KB':
                    return (int) ($value * 1024);
            }
        }
        
        return (int) $formatted;
    }

    /**
     * Truncar string
     */
    private function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }
        
        return substr($string, 0, $length - 3) . '...';
    }

    /**
     * Mostrar estadísticas generales
     */
    private function showGeneralStats(int $totalKeys, int $totalSize): void
    {
        $this->line('');
        $this->info('📊 Estadísticas Generales:');
        $this->line("   Total de claves: {$totalKeys}");
        $this->line("   Tamaño total: " . $this->formatBytes($totalSize));
        $this->line("   Límite Redis: 250 MB");
        
        $usagePercent = ($totalSize / (250 * 1024 * 1024)) * 100;
        $this->line("   Uso: " . round($usagePercent, 2) . "%");
        
        if ($usagePercent > 80) {
            $this->warn('   ⚠️  El caché está usando más del 80% del límite');
        } elseif ($usagePercent > 50) {
            $this->comment('   ⚠️  El caché está usando más del 50% del límite');
        } else {
            $this->info('   ✅ Uso de caché dentro de límites normales');
        }
    }
}
