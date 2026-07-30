<?php

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for pure helpers in owa_lib.
 *
 * These lock in CURRENT behavior so cleanup (replacing PHP4 shims and deprecated
 * stdlib calls) can be verified as behavior-preserving. OWA\Core\Lib
 * loads standalone via Composer's PSR-4 autoloader (vendor/autoload.php is
 * required by tests/bootstrap.php) with no framework bootstrap required.
 */

use OWA\Core\Lib;

final class OwaLibTest extends TestCase
{
    public function testImplodeAssoc(): void
    {
        $this->assertSame(
            'a=>1|||b=>2',
            Lib::implode_assoc('=>', '|||', ['a' => 1, 'b' => 2])
        );
    }

    public function testAssocFromStringRoundTrip(): void
    {
        $this->assertSame(
            ['a' => '1', 'b' => '2'],
            Lib::assocFromString('a=>1|||b=>2')
        );
    }

    public function testAssocFromStringWithoutOuterGlueReturnsInput(): void
    {
        $this->assertSame('justastring', Lib::assocFromString('justastring'));
    }
}
