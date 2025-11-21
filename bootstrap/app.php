<?php

use Illuminate\Foundation\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            // EnsureFrontendRequestsAreStateful::class,
            HandleCors::class,
        ]);
        
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RequestLoggingMiddleware::class,
        ]);
        
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'auth.token' => \App\Http\Middleware\AuthenticateWithApiToken::class,
            'ensure.api.user' => \App\Http\Middleware\EnsureApiTokenIsValid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Los datos proporcionados no son válidos.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'No autorizado.',
                ], 401);
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'message' => 'No tienes permisos para realizar esta acción.',
                ], 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Recurso no encontrado.',
                ], 404);
            }

            if ($e instanceof QueryException) {
                return response()->json([
                    'message' => 'Ocurrió un error al procesar la solicitud. Intenta nuevamente más tarde.',
                ], 500);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                $defaultMessages = [
                    400 => 'Solicitud inválida.',
                    401 => 'No autorizado.',
                    403 => 'No tienes permisos para realizar esta acción.',
                    404 => 'Recurso no encontrado.',
                    405 => 'Método no permitido.',
                    429 => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                ];

                $message = $defaultMessages[$status] ?? 'Ocurrió un error al procesar la solicitud.';

                return response()->json([
                    'message' => $message,
                ], $status);
            }

            report($e);

            return response()->json([
                'message' => 'Ha ocurrido un error inesperado. Intenta nuevamente más tarde.',
            ], 500);
        });
    })->create();
