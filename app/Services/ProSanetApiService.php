<?php

namespace App\Services;

use App\Exceptions\ProSanetApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProSanetApiService
{
    private const TOKEN_CACHE_KEY = 'prosanet:api:token';

    private const LOG_PREFIX = '[PROSANET API]';

    /** Máximo permitido por la API ProSanet (employees-api/summary). */
    private const SUMMARY_PER_PAGE = 100;

    /** Tiempo máximo de PHP al paginar el listado completo (solo en cache miss). */
    private const SUMMARY_SYNC_TIME_LIMIT_SECONDS = 300;

    private const MAX_LOG_BODY_LENGTH = 4000;

    /**
     * ProSanet ERP document type catalog (`erp_document_types`).
     *
     * @var array<string, int>
     */
    private const DOCUMENT_TYPE_LABEL_TO_ID = [
        'CC' => 1,
        'CE' => 2,
        'TI' => 3,
        'NUIP' => 4,
        'PE' => 5,
        'PT' => 6,
    ];

    public function isEnabled(): bool
    {
        if (! config('services.prosanet.enabled', true)) {
            return false;
        }

        $username = config('services.prosanet.username');
        $password = config('services.prosanet.password');

        return is_string($username) && $username !== ''
            && is_string($password) && $password !== '';
    }

    public function isAvailable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $this->getAccessToken();

            return true;
        } catch (ProSanetApiException $e) {
            Log::warning(self::LOG_PREFIX.' API no disponible para autenticación', array_merge(
                ['error' => $e->getMessage()],
                $e->contextForLog()
            ));

            return false;
        }
    }

    public function resolveDocumentTypeId(string $tipoDocumentoLabel): ?int
    {
        $normalized = strtoupper(trim($tipoDocumentoLabel));

        return self::DOCUMENT_TYPE_LABEL_TO_ID[$normalized] ?? null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
     */
    public function getSummary(array $filters = []): array
    {
        return $this->requestEmployeesEndpoint('employees-api/summary', $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
     */
    public function getDetail(array $filters = []): array
    {
        return $this->requestEmployeesEndpoint('employees-api/detail', $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function fetchAllSummaryItems(array $filters = []): array
    {
        $originalTimeLimit = ini_get('max_execution_time');
        if ($originalTimeLimit !== false && $originalTimeLimit !== '0') {
            set_time_limit(self::SUMMARY_SYNC_TIME_LIMIT_SECONDS);
        }

        try {
            return $this->paginateSummaryItems($filters);
        } finally {
            if ($originalTimeLimit !== false && $originalTimeLimit !== '0') {
                set_time_limit((int) $originalTimeLimit);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginateSummaryItems(array $filters): array
    {
        $page = 1;
        $allItems = [];

        do {
            $response = $this->getSummary(array_merge($filters, [
                'page' => $page,
                'per-page' => self::SUMMARY_PER_PAGE,
            ]));

            $items = $response['items'] ?? [];
            if (! is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $allItems[] = $item;
                }
            }

            $pagination = $response['pagination'] ?? [];
            $pageCount = max(1, (int) ($pagination['pageCount'] ?? 1));
            $totalCount = (int) ($pagination['totalCount'] ?? count($allItems));

            Log::info(self::LOG_PREFIX.' Página summary obtenida', [
                'page' => $page,
                'page_count' => $pageCount,
                'per_page' => self::SUMMARY_PER_PAGE,
                'items_in_page' => count($items),
                'items_collected' => count($allItems),
                'total_count' => $totalCount,
            ]);

            if ($page >= $pageCount) {
                break;
            }

            $page++;
        } while (true);

        Log::info(self::LOG_PREFIX.' Listado summary completado', [
            'pages_fetched' => $page,
            'items_collected' => count($allItems),
            'per_page' => self::SUMMARY_PER_PAGE,
        ]);

        return $allItems;
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_array($cached) && ! empty($cached['access_token'])) {
                $expireIn = isset($cached['expire_in']) ? (int) $cached['expire_in'] : 0;
                if ($expireIn === 0 || $expireIn > time() + 60) {
                    return (string) $cached['access_token'];
                }
            }
        }

        return $this->loginAndCacheToken();
    }

    private function loginAndCacheToken(): string
    {
        $username = config('services.prosanet.username');
        $password = config('services.prosanet.password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new ProSanetApiException('Credenciales ProSanet API no configuradas');
        }

        $route = 'erpuser/login';
        $url = $this->buildUrl($route);
        $payload = [
            'username' => $username,
            'password' => $password,
        ];

        try {
            Log::info(self::LOG_PREFIX.' Solicitando token JWT', [
                'request_method' => 'POST',
                'request_url' => $url,
                'request_payload' => $this->sanitizePayloadForLog($payload),
            ]);

            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->asForm()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            $this->throwApiException(
                message: 'Error de conexión al autenticar con ProSanet API: '.$e->getMessage(),
                method: 'POST',
                url: $url,
                payload: $payload,
                previous: $e,
            );
        }

        if ($response->failed()) {
            $this->throwApiException(
                message: 'Autenticación ProSanet API fallida',
                method: 'POST',
                url: $url,
                payload: $payload,
                httpStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            $this->throwApiException(
                message: 'Respuesta de login ProSanet API inválida',
                method: 'POST',
                url: $url,
                payload: $payload,
                httpStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        $accessToken = (string) $data['access_token'];
        $expireIn = isset($data['expireIn']) ? (int) $data['expireIn'] : 0;

        $ttlMinutes = (int) config('services.prosanet.token_cache_ttl_minutes', 55);
        Cache::put(self::TOKEN_CACHE_KEY, [
            'access_token' => $accessToken,
            'expire_in' => $expireIn,
        ], now()->addMinutes($ttlMinutes));

        Log::info(self::LOG_PREFIX.' Token JWT almacenado en caché', [
            'expire_in' => $expireIn,
            'ttl_minutes' => $ttlMinutes,
        ]);

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
     */
    private function requestEmployeesEndpoint(string $route, array $query = [], bool $retryOnUnauthorized = true): array
    {
        $normalizedQuery = $this->normalizeQueryParams($query);
        $fullUrl = $this->buildFullUrl($route, $normalizedQuery);
        $token = $this->getAccessToken();

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withToken($token)
                ->get($fullUrl);
        } catch (ConnectionException $e) {
            $this->throwApiException(
                message: 'Error de conexión con ProSanet API: '.$e->getMessage(),
                method: 'GET',
                url: $fullUrl,
                payload: $normalizedQuery,
                route: $route,
                previous: $e,
            );
        }

        if ($response->status() === 401 && $retryOnUnauthorized) {
            Log::warning(self::LOG_PREFIX.' Token expirado, renovando', [
                'route' => $route,
                'request_url' => $fullUrl,
            ]);
            Cache::forget(self::TOKEN_CACHE_KEY);
            $this->getAccessToken(true);

            return $this->requestEmployeesEndpoint($route, $query, false);
        }

        if ($response->serverError()) {
            $this->throwApiException(
                message: 'ProSanet API respondió con error de servidor',
                method: 'GET',
                url: $fullUrl,
                payload: $normalizedQuery,
                route: $route,
                httpStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        if ($response->failed()) {
            $this->throwApiException(
                message: 'ProSanet API respondió con error',
                method: 'GET',
                url: $fullUrl,
                payload: $normalizedQuery,
                route: $route,
                httpStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->throwApiException(
                message: 'Respuesta JSON inválida de ProSanet API',
                method: 'GET',
                url: $fullUrl,
                payload: $normalizedQuery,
                route: $route,
                httpStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'pagination' => is_array($data['pagination'] ?? null) ? $data['pagination'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function throwApiException(
        string $message,
        string $method,
        string $url,
        array $payload = [],
        ?string $route = null,
        ?int $httpStatus = null,
        ?string $responseBody = null,
        ?\Throwable $previous = null,
    ): never {
        $sanitizedPayload = $this->sanitizePayloadForLog($payload);
        [$loggedBody, $responseTruncated] = $this->prepareBodyForLog($responseBody);

        Log::warning(self::LOG_PREFIX.' Petición fallida', array_filter([
            'route' => $route,
            'request_method' => $method,
            'request_url' => $url,
            'request_payload' => $sanitizedPayload !== [] ? $sanitizedPayload : null,
            'http_status' => $httpStatus,
            'response_body' => $loggedBody,
            'response_body_truncated' => $responseTruncated ? true : null,
            'connection_error' => $previous instanceof ConnectionException ? $previous->getMessage() : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        throw new ProSanetApiException(
            $message,
            $httpStatus,
            $responseBody,
            $previous,
            $method,
            $url,
            $sanitizedPayload !== [] ? $sanitizedPayload : null,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function normalizeQueryParams(array $query): array
    {
        $normalized = [];

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildFullUrl(string $route, array $query = []): string
    {
        $url = $this->buildUrl($route);
        $params = $this->normalizeQueryParams($query);

        if ($params === []) {
            return $url;
        }

        return $url.'&'.http_build_query($params);
    }

    private function buildUrl(string $route): string
    {
        $baseUrl = rtrim((string) config('services.prosanet.base_url'), '/');

        return $baseUrl.'?r='.$route;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.prosanet.timeout', 15));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForLog(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'access_token', 'token'], true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function prepareBodyForLog(?string $body): array
    {
        if ($body === null || $body === '') {
            return [null, false];
        }

        if (strlen($body) <= self::MAX_LOG_BODY_LENGTH) {
            return [$body, false];
        }

        return [substr($body, 0, self::MAX_LOG_BODY_LENGTH).'… [truncated]', true];
    }
}
