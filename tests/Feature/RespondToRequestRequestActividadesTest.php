<?php

namespace Tests\Feature;

use App\Http\Requests\RespondToRequestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RespondToRequestRequestActividadesTest extends TestCase
{
    public function test_actividades_each_item_may_be_exactly_1200_characters(): void
    {
        $base = Request::create('/', 'POST', [
            'status' => 'COMPLETED',
            'email_subject' => str_repeat('s', 20),
            'email_body' => str_repeat('b', 100),
            'actividades' => [str_repeat('x', 1200)],
        ]);
        $formRequest = RespondToRequestRequest::createFrom($base);
        $validator = Validator::make($formRequest->all(), $formRequest->rules(), $formRequest->messages());

        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    public function test_actividades_each_item_rejects_more_than_1200_characters(): void
    {
        $base = Request::create('/', 'POST', [
            'status' => 'COMPLETED',
            'email_subject' => str_repeat('s', 20),
            'email_body' => str_repeat('b', 100),
            'actividades' => [str_repeat('x', 1201)],
        ]);
        $formRequest = RespondToRequestRequest::createFrom($base);
        $validator = Validator::make($formRequest->all(), $formRequest->rules(), $formRequest->messages());

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('1200', (string) $validator->errors()->first('actividades.0'));
    }
}
