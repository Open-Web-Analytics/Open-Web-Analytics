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
 * It did not. UsersPasswordEntry::action() puts the key in 'key', which is what
 * the view reads; UsersChangePassword::errorAction() put it in 'k'. View::get()
 * answers FALSE for a name nobody set, so the hidden field rendered as
 * value="" and the passkey was simply gone.
 *
 * The user-visible failure was a reset that could not be completed. Mistype the
 * confirmation once, get "Your passwords must match", correct it, submit -- and
 * that submit carried no key, so authenticateUserTempPasskey() refused it and
 * the redirect went to the login form with "can't find key in the db". Every
 * retry failed the same way. Only a fresh reset email, typed correctly the
 * first time, could get through.
 *
 * Reproduced against a live install before fixing: the first render carried
 * value="817b...", and the render after one mismatch carried value="".
 */
final class PasswordResetPasskeySurvivalTest extends TestCase
{
    private const PASSKEY = '0123456789abcdef0123456789abcdef';

    private function controllerData( array $params ): array
    {
        $controller = new \OWA\Module\Base\Controller\UsersChangePassword( $params );

        $controller->errorAction();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );

        return (array) $data->getValue( $controller );
    }

    /**
     * The name matters, not just the presence: the view asks for 'key', and a
     * value filed under any other name is invisible to it.
     */
    public function testTheRerenderedFormKeepsThePasskey(): void
    {
        $data = $this->controllerData( array(
            'k'         => self::PASSKEY,
            'password'  => 'abcdef1',
            'password2' => 'different2',
        ) );

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
        $data = $this->controllerData( array( 'k' => self::PASSKEY ) );

        $this->assertSame( 'base.usersPasswordEntry', $data['view'] ?? null );
    }

    /**
     * An absent passkey must stay absent rather than becoming a string that
     * looks like one -- authenticateUserTempPasskey should refuse it.
     */
    public function testAnAbsentPasskeyIsNotInvented(): void
    {
        $data = $this->controllerData( array( 'password' => 'abcdef1' ) );

        $this->assertEmpty( $data['key'] ?? null );
    }
}
