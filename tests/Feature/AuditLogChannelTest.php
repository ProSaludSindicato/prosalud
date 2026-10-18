<?php

namespace Tests\Feature;

use App\Services\AuditLogService;
use Tests\TestCase;

class AuditLogChannelTest extends TestCase
{
    private string $auditFile;

    private string $appFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditFile = storage_path('logs/audit-test-'.uniqid().'.log');
        $this->appFile = storage_path('logs/app-test-'.uniqid().'.log');

        config([
            'logging.audit_channel' => 'audit_test',
            'logging.default' => 'app_test',
            'logging.channels.audit_test' => ['driver' => 'single', 'path' => $this->auditFile, 'level' => 'info'],
            'logging.channels.app_test' => ['driver' => 'single', 'path' => $this->appFile, 'level' => 'debug'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->auditFile);
        @unlink($this->appFile);

        parent::tearDown();
    }

    public function test_who_did_what_events_go_to_the_audit_channel(): void
    {
        $service = new AuditLogService;

        $service->logAuthentication('login', ['user_id' => 7]);
        $service->logAdministrativeAction('role_changed');
        $service->logSecurityEvent('rate_limit');
        $service->logBusinessProcess('request_form', 'created');

        $audit = (string) file_get_contents($this->auditFile);

        foreach (['Authentication login', 'Administrative Action', 'Security Event', 'Business Process'] as $expected) {
            $this->assertStringContainsString($expected, $audit);
        }
        $this->assertFileDoesNotExist($this->appFile);
    }

    public function test_performance_metrics_stay_in_the_operational_log(): void
    {
        (new AuditLogService)->logPerformance('export', 120.0);

        $this->assertFileDoesNotExist($this->auditFile);
        $this->assertStringContainsString('Performance Metric', (string) file_get_contents($this->appFile));
    }
}
