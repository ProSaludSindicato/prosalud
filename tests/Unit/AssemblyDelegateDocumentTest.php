<?php

namespace Tests\Unit;

use App\Services\AssemblyDelegateDocument;
use PHPUnit\Framework\TestCase;

class AssemblyDelegateDocumentTest extends TestCase
{
    public function test_normalize_strips_spaces_and_decimal_suffix(): void
    {
        $this->assertSame('1234567', AssemblyDelegateDocument::normalize(' 1 234 567 '));
        $this->assertSame('1234567', AssemblyDelegateDocument::normalize('1234567.0'));
        $this->assertSame('AB12345', AssemblyDelegateDocument::normalize('AB12345'));
    }
}
