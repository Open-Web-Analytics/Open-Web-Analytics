<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A new password longer than User::MAX_PASSWORD_BYTES (72) is refused
 * wherever one is set: the web installer, the reset and invitation link
 * (base.usersChangePassword), User profile (base.myProfileSave) and
 * cmd=change-password. cmd=core-install is in CoreInstallCliTest. Bytes, not
 * characters: bcrypt reads 72 bytes.
 *
 * No database: each refusal is a validation, or comes before any query.
 */
final class PasswordMaxBytesTest extends TestCase
{
    /** 36 two-byte characters: 72 bytes. */
    const AT_THE_LIMIT = 'éééééééééééééééééééééééééééééééééééé';

    /** @var mixed */
    private $requestType;

    protected function setUp(): void
    {
        $this->requestType = \OWA\Core\CoreAPI::getSetting( 'base', 'request_type' );
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting( 'base', 'request_type', $this->requestType );
    }

    /** @return array<string, mixed> field => message */
    private function validationErrors( \OWA\Core\Controller $controller ): array
    {
        $controller->validate();
        $v = ( new \ReflectionProperty( \OWA\Core\Controller::class, 'v' ) )->getValue( $controller );
        $v->doValidations();

        return (array) $v->getErrorMsgs();
    }

    private static function tooLong(): string
    {
        // 37 two-byte characters: 37 characters, 74 bytes.
        return str_repeat( 'é', 37 );
    }

    /** @return array<string, array{callable(string): \OWA\Core\Controller, string}> */
    public static function screens(): array
    {
        return [
            'the web installer' => [ fn ( $p ) => new \OWA\Module\Base\Controller\InstallBase( [
                'domain' => 'https://example.test', 'user_id' => 'owatest-admin', 'email_address' => 'alice@example.com',
                'password' => $p, 'timezone' => 'Europe/Berlin' ] ), 'password_max_length' ],
            'a reset or invitation link' => [ fn ( $p ) => new \OWA\Module\Base\Controller\UsersChangePassword( [
                'password' => $p, 'password2' => $p, 'k' => 'x' ] ), 'password_max_length' ],
            'User profile' => [ fn ( $p ) => new \OWA\Module\Base\Controller\MyProfileSave( [
                'current_password' => 'current', 'new_password' => $p, 'new_password2' => $p ] ), 'password_max_length' ],
            'cmd=change-password' => [ function ( $p ) {

                // A command line controller is built only on a command line request.
                \OWA\Core\CoreAPI::setSetting( 'base', 'request_type', \OWA\Core\RequestType::CLI );

                return new \OWA\Module\Base\Controller\ChangeUserPasswordCli( [ 'user' => 'alice', 'password' => $p ] );
            }, 'password_max_length' ],
        ];
    }

    /** @dataProvider screens */
    public function testAPasswordOver72BytesIsRefused( callable $make, string $field ): void
    {
        $errors = $this->validationErrors( $make( self::tooLong() ) );

        $this->assertArrayHasKey( $field, $errors );
        $this->assertStringContainsString( 'at most 72 bytes', (string) $errors[ $field ] );
    }

    /** @dataProvider screens */
    public function testAPasswordOf72BytesIsAccepted( callable $make, string $field ): void
    {
        $this->assertSame( 72, strlen( self::AT_THE_LIMIT ), 'the fixture is at the limit' );

        $this->assertArrayNotHasKey( $field, $this->validationErrors( $make( self::AT_THE_LIMIT ) ) );
    }

    public function testTheMessage(): void
    {
        $this->assertSame( 'The password must be at most 72 bytes. An accented or non-Latin letter takes 2 to 4.',
            \OWA\Module\Base\Entity\User::passwordTooLongMessage() );
        $this->assertSame( 72, \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES );
    }

    /** User profile carries the refusal back as a code, with the limit filled in. */
    public function testUserProfileShowsTheLimit(): void
    {
        $this->assertSame( 3239, \OWA\Module\Base\Controller\MyProfileSave::VALIDATION_ERRORS['password_max_length'] );

        $form = new \OWA\Module\Base\Controller\MyProfile( [] );
        $text = new \ReflectionMethod( $form, 'carriedErrorText' );

        $this->assertSame( 'The password must be at most 72 bytes. An accented or non-Latin letter takes 2 to 4.',
            $text->invoke( $form, '3239' ) );
    }

    /** Below the screens: nothing hashes a password bcrypt would cut. */
    public function testNoAccountIsMadeOrChangedWithOne(): void
    {
        $this->assertFalse( ( new \OWA\Module\Base\Entity\User() )->createNewUser( 'alice', 'viewer', self::tooLong(), 'alice@example.com' ) );
        $this->assertFalse( ( new \OWA\Module\Base\Classes\UserManager() )->updateUserPassword(
            [ 'user_id' => 'alice', 'password' => self::tooLong() ] ) );
    }
}
