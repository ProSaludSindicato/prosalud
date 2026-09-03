<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    public function test_login_preflight_allows_explicit_configured_origin(): void
    {
        $origin = 'https://sindicatoprosalud.com';

        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    public function test_login_preflight_allows_additional_origin_from_config(): void
    {
        $origin = 'https://172.20.10.12:8080';

        config(['cors.allowed_origins' => [
            'https://sindicatoprosalud.com',
            'https://prosalud.org.co',
            $origin,
        ]]);

        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_login_preflight_rejects_unknown_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://evil.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_login_preflight_rejects_unlisted_local_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://172.20.10.12:8080',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
