<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a redirecting controller is allowed to carry into the query string.
 *
 * setRedirectAction() rebuilds the data container and copies the rest of it
 * across under a guard that reads as "only if this is neither an array nor an
 * object". It was written with || :
 *
 *     ! is_array( $param ) || ! is_object( $param )
 *
 * An array satisfies the second half and an object satisfies the first, so
 * every value passed and the filter held nothing back. Whatever it let through
 * reached Lib::redirectToView(), which concatenated it into the URL -- a PHP
 * warning, and the literal string "Array" as that parameter's value. That is
 * what base.updatesApply wrote to the Apache error log.
 *
 * Both halves are tested: the guard here, and the query builder in OwaLibTest,
 * because the success path of base.updatesApply sets view_method directly and
 * never passes through this guard at all.
 */
final class RedirectDataFilterTest extends TestCase
{
    private function controller( array $params = array() )
    {
        return new class( $params ) extends \OWA\Core\Controller {

            public function action() {

                return $this->data;
            }
        };
    }

    public function testScalarsAreCarriedIntoTheRedirect(): void
    {
        $c = $this->controller();
        $c->set('status_code', 3308);
        $c->set('siteId', 'abc123');

        $c->setRedirectAction('base.optionsGeneral');

        $this->assertSame(3308, $c->data['status_code']);
        $this->assertSame('abc123', $c->data['siteId']);
        $this->assertSame('base.optionsGeneral', $c->data['do']);
        $this->assertSame('redirect', $c->data['view_method']);
    }

    public function testArraysAndObjectsAreHeldBack(): void
    {
        $c = $this->controller();
        $c->set('kept', 'scalar');
        $c->set('modules', array('base', 'maxmind'));
        $c->set('thing', new stdClass);

        $c->setRedirectAction('base.optionsGeneral');

        $this->assertSame('scalar', $c->data['kept'],
            'the guard must still let scalars through');

        $this->assertArrayNotHasKey('modules', $c->data,
            'an array cannot survive a query string, and concatenating it '
            . 'writes the word "Array" into the URL');

        $this->assertArrayNotHasKey('thing', $c->data);
    }

    /**
     * The guard is reached at all. Without this the test above would pass on a
     * container that never held the values in the first place.
     */
    public function testTheValuesWerePresentBeforeTheRedirectWasSet(): void
    {
        $c = $this->controller();
        $c->set('modules', array('base'));

        $this->assertSame(array('base'), $c->data['modules']);

        $c->setRedirectAction('base.optionsGeneral');

        $this->assertArrayNotHasKey('modules', $c->data);
    }

    /**
     * Every save lands back on its screen with the Property being looked at:
     * an install-wide setting's save went to the first Property.
     */
    public function testTheRequestsPropertyGoesWithTheRedirect(): void
    {
        $c = $this->controller(array('propertyId' => 'prop-42'));

        $c->setRedirectAction('base.optionsGeneral');

        $this->assertSame('prop-42', $c->data['propertyId'] ?? null);
    }

    /** A Property the controller names, or none (one it deleted), wins. */
    public function testAPropertyTheControllerSetsWins(): void
    {
        $c = $this->controller(array('propertyId' => 'prop-42'));
        $c->set('propertyId', 'prop-7');
        $c->setRedirectAction('base.propertyProfiles');
        $this->assertSame('prop-7', $c->data['propertyId']);

        $c = $this->controller(array('propertyId' => 'prop-42'));
        $c->set('propertyId', '');
        $c->setRedirectAction('base.reportingHome');
        $this->assertSame('', $c->data['propertyId']);
    }

    public function testNoPropertyIsAddedWhenTheRequestHasNone(): void
    {
        $c = $this->controller();

        $c->setRedirectAction('base.optionsGeneral');

        $this->assertArrayNotHasKey('propertyId', $c->data);
    }

    /** One list: what a report link carries, less the Profile. */
    public function testTheRedirectCarriesTheReportLinkStateWithoutTheProfile(): void
    {
        $this->assertSame(
            array_values(array_diff(\OWA\Core\View::LINK_STATE_KEYS, array('siteId'))),
            \OWA\Core\Controller::reportState());

        $c = $this->controller(array('propertyId' => 'p1', 'siteId' => 's1', 'compare' => 'previous_period'));
        $c->setRedirectAction('base.optionsGeneral');

        $this->assertSame('previous_period', $c->data['compare'] ?? null);
        $this->assertArrayNotHasKey('siteId', $c->data);
    }
}
