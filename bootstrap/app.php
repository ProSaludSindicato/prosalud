<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
            'auth.token.optional' => \App\Http\Middleware\OptionalAuthenticateWithApiToken::class,
            'ensure.api.user' => \App\Http\Middleware\EnsureApiTokenIsValid::class,
            'recaptcha' => \App\Http\Middleware\VerifyRecaptcha::class,
            'dual-rate-limit' => \App\Http\Middleware\DualRateLimit::class,
            'candidate.voting.enabled' => \App\Http\Middleware\EnsureCandidateVotingEnabled::class,
            'assembly.voting.enabled' => \App\Http\Middleware\EnsureAssemblyVotingEnabled::class,
        ]);

        // La configuraci?n de rate limiters ahora est? en AppServiceProvider::boot()
        // para evitar el error "A facade root has not been set"
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Manejar excepciones de rate limiting (ThrottleRequestsException)
        // Asegurar que las rutas API siempre retornen JSON, incluso sin header Accept
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            // Para rutas API, siempre retornar JSON
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Demasiadas solicitudes. Intenta nuevamente m?s tarde.',
                    'error' => 'rate_limit_exceeded',
                ], 429);
            }

            // Para rutas web, retornar la respuesta por defecto de Laravel
            return null;
        });

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            $maxMb = round(max(1, (int) config('convenios.zip_max_kb', 51200)) / 1024, 1);
            $serverLimit = ini_get('post_max_size') ?: 'desconocido';

            return response()->json([
                'success' => false,
                'message' => "El archivo supera el l?mite de carga del servidor ({$serverLimit}). La aplicaci?n permite ZIP de hasta {$maxMb}MB. Aumente post_max_size y upload_max_filesize en PHP, o reduzca el tama?o del ZIP.",
                'error' => 'payload_too_large',
            ], 413);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Los datos proporcionados no son v?lidos.',
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
                    'message' => 'No tienes permisos para realizar esta acci?n.',
                ], 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Recurso no encontrado.',
                ], 404);
            }

            if ($e instanceof QueryException) {
                return response()->json([
                    'message' => 'Ocurri? un error al procesar la solicitud. Intenta nuevamente m?s tarde.',
                ], 500);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                $defaultMessages = [
                    400 => 'Solicitud inv?lida.',
                    401 => 'No autorizado.',
                    403 => 'No tienes permisos para realizar esta acci?n.',
                    404 => 'Recurso no encontrado.',
                    405 => 'M?todo no permitido.',
                    429 => 'Demasiadas solicitudes. Intenta nuevamente m?s tarde.',
                ];

                $message = $defaultMessages[$status] ?? 'Ocurri? un error al procesar la solicitud.';

                return response()->json([
                    'message' => $message,
                ], $status);
            }

            report($e);

            return response()->json([
                'message' => 'Ha ocurrido un error inesperado. Intenta nuevamente m?s tarde.',
            ], 500);
        });
    })->create();
