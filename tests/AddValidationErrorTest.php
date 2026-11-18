<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Controller::addValidationError(): the field fails validation with the
 * message given, and the request goes to errorAction().
 */
final class AddValidationErrorTest extends TestCase
{
    public function testTheFieldFailsWithTheMessage(): void
    {
        $controller = new class( [] ) extends \OWA\Core\Controller {

            public function validate() {

                $this->addValidationError( 'thing', 'That thing is not allowed.' );
            }

            function action() {

                $this->set( 'ran', 'action' );
            }

            function errorAction() {

                $this->set( 'ran', 'errorAction' );
            }
        };

        $data = $controller->doAction();

        $this->assertSame( 'errorAction', $data['ran'] ?? null );
        $this->assertSame( 'That thing is not allowed.', $data['validation_errors']['thing'] ?? null );
    }
}
