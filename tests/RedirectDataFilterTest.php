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
    private function controller()
    {
        return new class( array() ) extends \OWA\Core\Controller {

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
}
