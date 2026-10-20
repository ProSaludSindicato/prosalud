<?php

namespace Tests\Feature;

use App\Logging\SanitizeLogContext;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Tests\TestCase;

class LogSanitizationContextTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/sanitize-test-'.uniqid().'.log');

        config(['logging.channels.sanitize_test' => [
            'driver' => 'single',
            'path' => $this->logFile,
            'level' => 'debug',
            'tap' => [SanitizeLogContext::class],
        ]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    private function contents(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function test_personal_data_is_redacted_from_any_log_context(): void
    {
        Log::channel('sanitize_test')->info('probe', [
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-05-17',
            'email' => 'persona@example.com',
            'input' => ['numeroCuenta' => '9988776655', 'telefono' => '3001234567', 'nested' => ['password' => 'secreta']],
            'signature' => 'data:image/png;base64,AAAA',
            'candidate_election_key' => 'delegados-2026-1',
        ]);

        $log = $this->contents();

        foreach (['1234567890', '2010-05-17', 'persona@example.com', '9988776655', '3001234567', 'secreta', 'base64,AAAA'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }

        $this->assertStringContainsString('delegados-2026-1', $log);
        $this->assertStringContainsString('7890', $log);
    }

    public function test_array_values_under_sensitive_keys_do_not_break_logging(): void
    {
        Log::channel('sanitize_test')->warning('probe-array', ['documento' => ['1', '2'], 'otp' => new \stdClass]);

        $this->assertStringContainsString('probe-array', $this->contents());
    }

    public function test_tap_skips_handlers_that_cannot_hold_processors(): void
    {
        $monolog = new Logger('probe', [new NullHandler]);

        (new SanitizeLogContext)(new LaravelLogger($monolog));

        $this->assertCount(1, $monolog->getHandlers());
    }

    public function test_every_configured_channel_gets_the_sanitizing_tap(): void
    {
        foreach (array_keys(config('logging.channels')) as $channel) {
            $this->assertContains(SanitizeLogContext::class, (array) config("logging.channels.{$channel}.tap"), $channel);
        }
    }
}
