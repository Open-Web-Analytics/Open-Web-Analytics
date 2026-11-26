<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The reset form must not say whether an account exists.
 *
 * It is unauthenticated and takes an e-mail address, so any difference between
 * the known and unknown case is an oracle: ask it about an address and it tells
 * you whether that person has an account here.
 *
 * Both cases take the same path and get the same reply. The handler only
 * sends mail when there is somebody to send it to, and that difference is not
 * visible from outside.
 */
final class PasswordResetDoesNotDiscloseAccountsTest extends TestCase
{
    /** @var list<string> the addresses base.reset_password was raised for */
    public static array $raised = [];

    protected function tearDown(): void
    {
        // Whatever an action left for after the response, run here rather than at the end of the run.
        \OWA\Core\CoreAPI::runAfterResponse();
        self::$raised = [];
    }

    /**
     * The handler writes a token and sends mail only for a known address, so
     * its time would tell the two apart: it runs after the reply has gone.
     */
    public function testTheResetIsHandledAfterTheResponse(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the reset handler looks the address up' );
        }

        \OWA\Core\CoreAPI::getEventDispatch()->attach( 'base.reset_password',
            [ new ResetPasswordRecorder(), 'notify' ], 'test|ResetPasswordRecorder::notify' );

        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest( [ 'email_address' => 'nobody@example.com' ] );
        $controller->action();

        $this->assertSame( [], self::$raised, 'not while the reply is made' );
        $this->assertNotEmpty( $controller->data['status_msg'] ?? null, 'the reply is made' );

        \OWA\Core\CoreAPI::runAfterResponse();

        $this->assertSame( [ 'nobody@example.com' ], self::$raised );

        \OWA\Core\CoreAPI::runAfterResponse();
        $this->assertCount( 1, self::$raised, 'once' );
    }

    public function testTheRequestFormDoesNotCheckWhetherTheAccountExists(): void
    {
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest(
            [ 'email_address' => 'nobody@example.com' ] );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $validator = $v->getValue( $controller );

        $this->assertNotEmpty(
            $validator, 'No validator at all -- the format check should still be here.' );

        $property = new \ReflectionProperty( $validator, 'validations' );

        /*
         * The TYPE is the validation object's class, not the entry's name --
         * every entry here is named 'email_address'. An earlier version of this
         * test checked the name and so passed with the existence check present,
         * which is the whole failure it exists to catch.
         */
        $types = [];

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
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest( [] );

        $message = $controller->getMsg( 2000, [ 'message' => [ 'someone@example.com' ] ] );

        $this->assertStringContainsStringIgnoringCase( 'if an account exists', $message['message'] );

        foreach ( [ 'was not found', 'does not exist', 'has been sent to someone@' ] as $tell ) {

            $this->assertStringNotContainsStringIgnoringCase(
                $tell, $message['message'],
                'The reply distinguishes a known address from an unknown one.' );
        }
    }

    public function testTheErrorMessageIsAboutFormatNotExistence(): void
    {
        $controller = new \OWA\Module\Base\Controller\PasswordResetRequest( [] );

        $message = $controller->getMsg( 2001 );

        foreach ( [ 'not found', 'does not exist', 'database' ] as $tell ) {

            $this->assertStringNotContainsStringIgnoringCase(
                $tell, $message['message'],
                'The malformed-address error still reports whether the address is known.' );
        }
    }

    /**
     * Both replies are built with the address as a substitution list: the
     * success reply names it in the message and leaves the headline alone, and
     * the refusal, which has nothing to substitute, is message 2001 as it
     * stands (MessageSubstitutionTest holds getMsg() to that).
     */
    public function testBothRepliesAreBuiltFromTheAddressGiven(): void
    {
        $address = 'nobody-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '@owatest.example.com';
        $params  = [ 'email_address' => ' ' . $address . ' ' ];

        $refused = new \OWA\Module\Base\Controller\PasswordResetRequest( $params );
        $refused->errorAction();

        $this->assertSame( $refused->getMsg( 2001 ), $refused->data['error_msg'] ?? null );

        if ( ! owa_test_db_available() ) {
            return;
        }

        // An address no user has: the reset handler finds no one and sends nothing.
        $sent = new \OWA\Module\Base\Controller\PasswordResetRequest( $params );
        $sent->action();

        $this->assertStringContainsString( 'If an account exists for ' . $address . ',',
            $sent->data['status_msg']['message'] ?? '' );
        $this->assertSame( $sent->getMsg( 2000 )['headline'], $sent->data['status_msg']['headline'] ?? null );
    }
}

/** Records the address of each base.reset_password event. */
final class ResetPasswordRecorder
{
    /** @param object $event */
    public function notify($event): void
    {
        PasswordResetDoesNotDiscloseAccountsTest::$raised[] = (string) $event->get('email_address');
    }
}
