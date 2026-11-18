<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A mistyped password must not end the reset.
 *
 * The reset link carries the one-time passkey as 'k'. The form re-posts it in a
 * hidden field, so the passkey has to survive every re-render -- including the
 * one that happens when validation fails.
 *
 * UsersPasswordEntry::action() puts the key in 'key', which is what the view
 * reads, so the re-render after a failed validation has to put it there too:
 * View::get() answers FALSE for a name nobody set, so the hidden field would
 * render as value="", the next submit would carry no key, and
 * authenticateUserTempPasskey() would refuse it -- every retry failing the
 * same way.
 */
final class PasswordResetPasskeySurvivalTest extends TestCase
{
    private const PASSKEY = '0123456789abcdef0123456789abcdef';

    private function controllerData( array $params ): array
    {
        $controller = new \OWA\Module\Base\Controller\UsersChangePassword( $params );

        $controller->errorAction();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );

        return (array) $data->getValue( $controller );
    }

    /**
     * The name matters, not just the presence: the view asks for 'key', and a
     * value filed under any other name is invisible to it.
     */
    public function testTheRerenderedFormKeepsThePasskey(): void
    {
        $data = $this->controllerData( [
            'k'         => self::PASSKEY,
            'password'  => 'abcdef1',
            'password2' => 'different2',
        ] );

        $this->assertArrayHasKey(
            'key', $data,
            'The view reads "key". Filing the passkey anywhere else renders the hidden '
            . 'field empty and ends the reset.' );

        $this->assertSame( self::PASSKEY, $data['key'] );
    }

    /**
     * The view it re-renders has to be the entry form, or the key has nowhere
     * to go however it is named.
     */
    public function testItRerendersThePasswordEntryForm(): void
    {
        $data = $this->controllerData( [ 'k' => self::PASSKEY ] );

        $this->assertSame( 'base.usersPasswordEntry', $data['view'] ?? null );
    }

    /**
     * An absent passkey must stay absent rather than becoming a string that
     * looks like one -- authenticateUserTempPasskey should refuse it.
     */
    public function testAnAbsentPasskeyIsNotInvented(): void
    {
        $data = $this->controllerData( [ 'password' => 'abcdef1' ] );

        $this->assertEmpty( $data['key'] ?? null );
    }
}
