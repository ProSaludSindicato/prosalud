<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_api_responses_carry_a_generated_request_id(): void
    {
        $response = $this->getJson('/api/voting/mode');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->headers->get('X-Request-Id'));
    }

    public function test_a_valid_incoming_request_id_is_kept(): void
    {
        $this->getJson('/api/voting/mode', ['X-Request-Id' => 'trace-1234-abcd'])
            ->assertHeader('X-Request-Id', 'trace-1234-abcd');
    }

    public function test_an_unsafe_incoming_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/voting/mode', ['X-Request-Id' => "bad id\nwith newline"]);

        $this->assertNotSame("bad id\nwith newline", $response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->headers->get('X-Request-Id'));
    }
}
