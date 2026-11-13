<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA is never embedded in a host application, so base.is_embedded is not a
 * setting, and the password reset screens behave as a standalone install's.
 *
 * The reset link carries the passkey as 'k'; the form posts it back with the
 * action and nothing else. A request parameter named is_embedded is ignored.
 */
final class EmbeddedSettingRemovedTest extends TestCase
{
    private const PASSKEY_HEX = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private function currentPasskey(): string
    {
        return self::PASSKEY_HEX . '.' . ( time() + 600 );
    }

    private function controllerData( \OWA\Core\Controller $controller ): array
    {
        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );

        return (array) $data->getValue( $controller );
    }

    public function testTheSettingIsNotDeclared(): void
    {
        $this->assertFalse(
            \OWA\Core\CoreAPI::configSingleton()->isRegistered( 'base', 'is_embedded' ) );

        $declaration = include OWA_DIR . 'modules/Base/settings.php';

        $this->assertArrayNotHasKey( 'is_embedded', (array) $declaration['settings'] );
    }

    public function testThePasswordEntryScreenIsGivenOnlyTheKey(): void
    {
        $key = $this->currentPasskey();

        $controller = new \OWA\Module\Base\Controller\UsersPasswordEntry(
            array( 'k' => $key, 'is_embedded' => '1' ) );

        $controller->action();

        $data = $this->controllerData( $controller );

        $this->assertSame( 'base.usersPasswordEntry', $data['view'] ?? null );
        $this->assertSame( $key, $data['key'] ?? null );
        $this->assertArrayNotHasKey( 'is_embedded', $data );
    }

    public function testThePasswordFormPostsThePasskeyAndTheActionOnly(): void
    {
        $key = $this->currentPasskey();

        $template = new \OWA\Core\Template();
        $template->set_template( 'users_change_password.php' );
        $template->set( 'key', $key );

        $html = (string) $template->fetch();

        $this->assertStringContainsString( 'Set your password', $html,
            'an empty render is what a template error looks like' );

        preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $html, $m );

        $this->assertSame( array( 'k', 'action' ), $m[1] );
        $this->assertSame( array( $key, 'base.usersChangePassword' ), $m[2] );
    }

    /**
     * A key no account holds is refused before the database is asked, so this
     * needs none: the request is turned away, and sets nothing on the way.
     */
    public function testChangingAPasswordSetsNoEmbeddedFlag(): void
    {
        $controller = new \OWA\Module\Base\Controller\UsersChangePassword( array(
            'k'           => self::PASSKEY_HEX,
            'password'    => 'abcdefgh1',
            'password2'   => 'abcdefgh1',
            'is_embedded' => '1',
        ) );

        $controller->action();

        $data = $this->controllerData( $controller );

        $this->assertSame( 'base.loginForm', $data['do'] ?? null );
        $this->assertSame( 2011, $data['error_code'] ?? null );
        $this->assertFalse( \OWA\Core\CoreAPI::getSetting( 'base', 'is_embedded' ) );
    }
}
