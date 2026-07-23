<?php

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for pure helpers in owa_lib.
 *
 * These lock in CURRENT behavior so the Phase 1 cleanup (replacing PHP4 shims
 * and deprecated stdlib calls) can be verified as behavior-preserving. owa_lib
 * loads standalone with no framework bootstrap required.
 */
final class OwaLibTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../owa_lib.php';
    }

    public function testImplodeAssoc(): void
    {
        $this->assertSame(
            'a=>1|||b=>2',
            owa_lib::implode_assoc('=>', '|||', ['a' => 1, 'b' => 2])
        );
    }

    public function testAssocFromStringRoundTrip(): void
    {
        $this->assertSame(
            ['a' => '1', 'b' => '2'],
            owa_lib::assocFromString('a=>1|||b=>2')
        );
    }

    public function testAssocFromStringWithoutOuterGlueReturnsInput(): void
    {
        $this->assertSame('justastring', owa_lib::assocFromString('justastring'));
    }
}
