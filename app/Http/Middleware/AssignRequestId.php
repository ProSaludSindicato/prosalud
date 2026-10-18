<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Give every API request an id shared by all its log lines and returned to the client,
     * so a user-reported error can be traced end to end.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header(self::HEADER);
        $requestId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);

        Log::withContext([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
