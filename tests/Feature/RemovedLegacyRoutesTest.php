<?php

namespace Tests\Feature;

use Tests\TestCase;

class RemovedLegacyRoutesTest extends TestCase
{
    public function test_certificate_test_page_is_not_publicly_exposed(): void
    {
        $this->get('/test-certificado')->assertNotFound();
    }
}
