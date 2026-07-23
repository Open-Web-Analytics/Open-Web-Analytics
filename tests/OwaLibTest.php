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

    /**
     * array_intersect_key is a PHP4 shim scheduled for removal in Step 3; this
     * pins its behavior so the removal (delegating to the native builtin) is safe.
     */
    public function testArrayIntersectKey(): void
    {
        $this->assertSame(
            ['a' => 1, 'c' => 3],
            owa_lib::array_intersect_key(
                ['a' => 1, 'b' => 2, 'c' => 3],
                ['a' => 9, 'c' => 9]
            )
        );
    }
}
