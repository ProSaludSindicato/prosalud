<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_ok_when_dependencies_work(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['database' => true, 'cache' => true]]);
    }

    public function test_health_returns_503_without_leaking_details_when_the_database_fails(): void
    {
        DB::shouldReceive('select')->andThrow(new \RuntimeException('SQLSTATE secret-host'));

        $response = $this->getJson('/health')->assertStatus(503)->assertJson(['status' => 'degraded']);

        $this->assertStringNotContainsString('secret-host', $response->getContent());
    }
}
