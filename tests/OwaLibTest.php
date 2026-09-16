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

    /*
     * buildRedirectQuery(): the query string a redirect-to-view carries.
     *
     * Split out of redirectToView(), which ends in a Location header a test
     * cannot read. What follows is every rule that half applies.
     */

    public function testScalarsBecomeNamespacedPairs(): void
    {
        $this->assertSame(
            'owa_do=base.optionsGeneral&owa_status_code=3308&',
            Lib::buildRedirectQuery(
                ['do' => 'base.optionsGeneral', 'status_code' => 3308], 'owa_')
        );
    }

    public function testControlParamsAreLeftOut(): void
    {
        $this->assertSame(
            'do=base.updates&',
            Lib::buildRedirectQuery(
                ['do' => 'base.updates', 'view_method' => 'redirect', 'auth_status' => true],
                '', ['view_method', 'auth_status'])
        );
    }

    /**
     * An array used to be concatenated into the string, which is a PHP warning
     * and puts the literal "Array" in the URL as the value. This is what was
     * reaching the Apache error log from base.updatesApply.
     */
    public function testNonScalarValuesAreDroppedRatherThanStringified(): void
    {
        $query = Lib::buildRedirectQuery(
            ['do' => 'base.updates', 'modules' => ['base', 'maxmind'], 'obj' => new stdClass], '');

        $this->assertSame('do=base.updates&', $query);
        $this->assertStringNotContainsString('Array', $query);
    }

    /**
     * Raw interpolation let a value rewrite the rest of the query string.
     */
    public function testNamesAndValuesAreEncoded(): void
    {
        $this->assertSame(
            'owa_go=a%26b%3Dc%20d&',
            Lib::buildRedirectQuery(['go' => 'a&b=c d'], 'owa_')
        );
    }

    /**
     * Not decoration: the '.' in every action name, and the '_' the namespace
     * is made of, must survive unencoded or every redirect target changes.
     */
    public function testUnreservedCharactersSurviveUnencoded(): void
    {
        $this->assertSame(
            'owa_do=base.reportDashboard&',
            Lib::buildRedirectQuery(['do' => 'base.reportDashboard'], 'owa_')
        );
    }

    public function testAnEmptyContainerProducesAnEmptyQuery(): void
    {
        $this->assertSame('', Lib::buildRedirectQuery([], 'owa_'));
    }
}
