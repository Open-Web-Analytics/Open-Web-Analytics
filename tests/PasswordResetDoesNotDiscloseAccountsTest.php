<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The reset form must not say whether an account exists.
 *
 * It is unauthenticated and takes an e-mail address, so any difference between
 * the known and unknown case is an oracle: ask it about an address and it tells
 * you whether that person has an account here. It answered
 * "A user with that email address does not exist" for an unknown address and
 * "an e-mail ... has been sent to X" for a known one -- about as direct as that
 * gets, one guess at a time, with nothing to slow it down.
 *
 * Both cases now take the same path and get the same reply. The handler still
 * only sends mail when there is somebody to send it to; the difference is that
 * the difference is no longer visible from outside.
 */
final class PasswordResetDoesNotDiscloseAccountsTest extends TestCase
{
    public function testTheRequestFormDoesNotCheckWhetherTheAccountExists(): void
    {
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest(
            array( 'email_address' => 'nobody@example.com' ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        $this->assertNotEmpty(
            $validator, 'No validator at all -- the format check should still be here.' );

        $property = new \ReflectionProperty( $validator, 'validations' );
        $property->setAccessible( true );

        /*
         * The TYPE is the validation object's class, not the entry's name --
         * every entry here is named 'email_address'. An earlier version of this
         * test checked the name and so passed with the existence check present,
         * which is the whole failure it exists to catch.
         */
        $types = array();

        foreach ( (array) $property->getValue( $validator ) as $validation ) {

            $types[] = get_class( $validation['obj'] );
        }

        $this->assertNotEmpty( $types, 'The format check should still be registered.' );

        foreach ( $types as $type ) {

            $this->assertStringNotContainsStringIgnoringCase(
                'entityExists', $type,
                'An existence check on an unauthenticated form is an account oracle. '
                . 'Registered validations: ' . implode( ', ', $types ) );
        }
    }

    /**
     * The wording is the control, so it is asserted rather than left to review:
     * a message naming what was or was not found puts the oracle back.
     */
    public function testTheSuccessMessageIsConditionalAndSaysNothingAboutTheAccount(): void
    {
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest( array() );

        $message = $controller->getMsg( 2000, array( 'message' => array( 'someone@example.com' ) ) );

        $this->assertStringContainsStringIgnoringCase( 'if an account exists', $message['message'] );

        foreach ( array( 'was not found', 'does not exist', 'has been sent to someone@' ) as $tell ) {

            $this->assertStringNotContainsStringIgnoringCase(
                $tell, $message['message'],
                'The reply distinguishes a known address from an unknown one.' );
        }
    }

    public function testTheErrorMessageIsAboutFormatNotExistence(): void
    {
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest( array() );

        $message = $controller->getMsg( 2001 );

        foreach ( array( 'not found', 'does not exist', 'database' ) as $tell ) {

            $this->assertStringNotContainsStringIgnoringCase(
                $tell, $message['message'],
                'The malformed-address error still reports whether the address is known.' );
        }
    }
}
