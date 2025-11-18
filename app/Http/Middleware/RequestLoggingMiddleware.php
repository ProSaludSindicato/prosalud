<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLoggingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (Response) $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $startTime = microtime(true);

        if ($this->shouldLogRequest($request)) {
            Log::info('HTTP Request iniciada', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);
        }

        $response = $next($request);

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->shouldLogRequest($request)) {
            Log::info('HTTP Request completada', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'status_code' => $response->getStatusCode(),
                'execution_time_ms' => $executionTime,
                'ip_address' => $request->ip(),
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);
        }

        return $response;
    }

    /**
     * Determine if the request should be logged.
     */
    private function shouldLogRequest(Request $request): bool
    {
        $criticalEndpoints = [
            'api/requests',
            'api/users',
            'api/wellness-events',
            'api/chatbot-conversations',
            'api/incapacidades/search',
            'api/liquidaciones/search',
            'api/roles',
            'api/permissions',
        ];

        $path = $request->path();

        foreach ($criticalEndpoints as $endpoint) {
            if (str_starts_with($path, $endpoint)) {
                return true;
            }
        }

        return false;
    }
}
