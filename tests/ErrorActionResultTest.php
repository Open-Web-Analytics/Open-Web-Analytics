<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * doAction() must USE what errorAction() returns.
 *
 * WHAT THIS IS ABOUT
 *
 * The success path hands back whatever action() returns. The validation-failure
 * path called errorAction() for its side effects and then returned $this->data
 * regardless -- so a controller that answers a refusal by DELEGATING (building
 * another controller and returning its doAction()) had that answer discarded,
 * and the caller rendered the refusing controller's own data. For a write that
 * redirects on success, that data names no view at all, and the result was a
 * blank page. base.customReportSave did exactly that for a missing report name.
 *
 * The two shapes are tested rather than one, because the fix has to leave the
 * common one alone: nearly every errorAction() in the tree returns nothing and
 * sets a view instead, and those must behave exactly as they did.
 */
final class ErrorActionResultTest extends TestCase
{
    /** A controller whose validation always fails. */
    private function failing( callable $errorAction ): object
    {
        return new class( array(), $errorAction ) extends \OWA\Core\Controller {

            /** @var callable */
            private $onError;

            public function __construct( $params, $onError = null ) {

                $this->onError = $onError;

                parent::__construct( $params );
            }

            public function validate() {

                // Always fails, which is what routes doAction() to errorAction().
                $this->addValidation( 'required_thing', '', 'required' );
            }

            function action() {

                $this->set( 'ran', 'action' );
            }

            function errorAction() {

                return call_user_func( $this->onError, $this );
            }
        };
    }

    /**
     * A returned value is handed back.
     *
     * This is the delegating shape: errorAction() answers with somebody else's
     * rendered data, and that is what the caller must receive.
     */
    public function testAReturnedResultIsUsed(): void
    {
        $delegated = array( 'view' => 'base.report', 'subview' => 'base.customReportEdit' );

        $controller = $this->failing( static function () use ( $delegated ) {

            return $delegated;
        } );

        $this->assertSame( $delegated, $controller->doAction(),
            'a delegating errorAction() had its answer discarded, which rendered a '
            . 'controller that names no view -- a blank page' );
    }

    /**
     * ...and returning nothing still answers with the controller's own data.
     *
     * The overwhelmingly common shape: set a view, return nothing. It must be
     * untouched by the fix, so this asserts the data the errorAction set is
     * what comes back.
     */
    public function testReturningNothingStillAnswersWithTheControllersOwnData(): void
    {
        $controller = $this->failing( static function ( $self ) {

            $self->set( 'ran', 'errorAction' );

            return null;
        } );

        $result = (array) $controller->doAction();

        $this->assertSame( 'errorAction', $result['ran'] ?? null,
            'an errorAction() that sets state and returns nothing must still have its '
            . 'state answered with' );

        $this->assertArrayHasKey( 'validation_errors', $result,
            'the messages the failure produced travel with it' );
    }

    /**
     * An empty return is treated as no return.
     *
     * A controller that ends with a bare `return;` or hands back an empty array
     * is saying "I set what I needed", not "answer with nothing" -- so the
     * data still has to come back.
     */
    public function testAnEmptyReturnFallsBackToTheControllersData(): void
    {
        $controller = $this->failing( static function ( $self ) {

            $self->set( 'ran', 'empty' );

            return array();
        } );

        $result = (array) $controller->doAction();

        $this->assertSame( 'empty', $result['ran'] ?? null );
    }

    /** The failure path must not run action(). */
    public function testTheNormalActionDoesNotRun(): void
    {
        $controller = $this->failing( static function () {

            return null;
        } );

        $result = (array) $controller->doAction();

        $this->assertNotSame( 'action', $result['ran'] ?? null );
    }
}
