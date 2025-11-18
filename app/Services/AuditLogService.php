<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * Log user authentication events.
     */
    public function logAuthentication(string $action, array $context = []): void
    {
        Log::info("Audit: Authentication {$action}", array_merge([
            'action' => $action,
            'type' => 'authentication',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Log data access events.
     */
    public function logDataAccess(string $resource, string $action, array $context = []): void
    {
        Log::info("Audit: Data Access {$action}", array_merge([
            'resource' => $resource,
            'action' => $action,
            'type' => 'data_access',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Log administrative actions.
     */
    public function logAdministrativeAction(string $action, array $context = []): void
    {
        Log::info('Audit: Administrative Action', array_merge([
            'action' => $action,
            'type' => 'administrative',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Log system performance metrics.
     */
    public function logPerformance(string $operation, float $executionTime, array $context = []): void
    {
        $level = $executionTime > 5000 ? 'warning' : 'info'; // Log as warning if > 5 seconds

        Log::{$level}('Audit: Performance Metric', array_merge([
            'operation' => $operation,
            'execution_time_ms' => $executionTime,
            'type' => 'performance',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Log security events.
     */
    public function logSecurityEvent(string $event, array $context = []): void
    {
        Log::warning('Audit: Security Event', array_merge([
            'event' => $event,
            'type' => 'security',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Log business process events.
     */
    public function logBusinessProcess(string $process, string $action, array $context = []): void
    {
        Log::info('Audit: Business Process', array_merge([
            'process' => $process,
            'action' => $action,
            'type' => 'business_process',
            'timestamp' => now()->toISOString(),
        ], $context));
    }

    /**
     * Add request context to audit log.
     */
    public function addRequestContext(Request $request, array $context = []): array
    {
        return array_merge($context, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => $request->user()?->id,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ]);
    }
}
