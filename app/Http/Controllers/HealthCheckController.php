<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HealthCheckController extends Controller
{
    /**
     * Readiness probe: verifies the dependencies the app cannot work without. Details are only logged.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'cache' => $this->check('cache', function () {
                Cache::put('health-check', true, 10);

                return Cache::get('health-check') === true;
            }),
        ];

        if (config('queue.default') === 'redis' || config('cache.default') === 'redis') {
            $checks['redis'] = $this->check('redis', fn () => Redis::connection()->ping());
        }

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function check(string $name, callable $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (\Throwable $e) {
            Log::error('Health check failed', ['check' => $name, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
