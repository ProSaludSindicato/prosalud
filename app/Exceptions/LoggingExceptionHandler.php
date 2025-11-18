<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LoggingExceptionHandler extends ExceptionHandler
{
    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (\Throwable $e) {
            $this->logException($e);
        });
    }

    /**
     * Log exceptions with contextual information.
     */
    protected function logException(\Throwable $e): void
    {
        $context = [
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()->toISOString(),
        ];

        if (request()) {
            $context['request'] = [
                'method' => request()->method(),
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'user_id' => request()->user()?->id,
            ];
        }

        if ($e instanceof HttpException) {
            $this->logHttpException($e, $context);
        } else {
            $this->logGenericException($e, $context);
        }
    }

    /**
     * Log HTTP exceptions with appropriate level.
     */
    protected function logHttpException(HttpException $e, array $context): void
    {
        $statusCode = $e->getStatusCode();

        if ($statusCode >= 500) {
            Log::error("HTTP {$statusCode} Error", $context);
        } elseif ($statusCode >= 400) {
            Log::warning("HTTP {$statusCode} Error", $context);
        } else {
            Log::info("HTTP {$statusCode} Error", $context);
        }
    }

    /**
     * Log generic exceptions.
     */
    protected function logGenericException(\Throwable $e, array $context): void
    {
        $logLevel = $this->getLogLevelForException($e);

        Log::{$logLevel}("Exception: {$e->getMessage()}", $context);
    }

    /**
     * Determine appropriate log level for exception.
     */
    protected function getLogLevelForException(\Throwable $e): string
    {
        $exceptionClass = get_class($e);

        $criticalExceptions = [
            \Illuminate\Database\QueryException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
        ];

        foreach ($criticalExceptions as $criticalException) {
            if ($e instanceof $criticalException) {
                return 'error';
            }
        }

        return 'error';
    }
}
