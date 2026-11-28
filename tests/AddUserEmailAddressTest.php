<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every way of making an account refuses an email address that is not one:
 * the Add user screen and POST base/v1/users (base.usersAdd), and the web
 * installer (base.installBase). cmd=core-install is covered by CoreInstallCliTest.
 *
 * No database: the address check stops validation before anything is looked
 * up.
 */
final class AddUserEmailAddressTest extends TestCase
{
    /** @return array<string, mixed> field => message */
    private function validationErrors( \OWA\Core\Controller $controller ): array
    {
        $controller->validate();
        $v = ( new \ReflectionProperty( \OWA\Core\Controller::class, 'v' ) )->getValue( $controller );
        $v->doValidations();

        return (array) $v->getErrorMsgs();
    }

    /** @return array<string, array{string}> */
    public static function notAddresses(): array
    {
        return [
            'no @'          => [ 'alice' ],
            'no domain'     => [ 'alice@' ],
            'two addresses' => [ 'alice@example.com bob@example.com' ],
        ];
    }

    /** @dataProvider notAddresses */
    public function testAddingAUserRefusesIt( string $address ): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\UsersAdd(
            [ 'user_id' => 'owatest-email', 'email_address' => $address, 'role' => 'viewer' ] ) );

        $this->assertSame( 'Enter a valid email address.', $errors['email_address'] ?? null );
    }

    /** @dataProvider notAddresses */
    public function testTheRestRouteRefusesIt( string $address ): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\AddUserRest(
            [ 'user_id' => 'owatest-email', 'email_address' => $address, 'role' => 'viewer' ] ) );

        $this->assertSame( 'Enter a valid email address.', $errors['email_address'] ?? null );
    }

    /** @dataProvider notAddresses */
    public function testTheWebInstallerRefusesIt( string $address ): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\InstallBase( [
            'domain'        => 'https://example.test',
            'user_id'       => 'owatest-admin',
            'email_address' => $address,
            'password'      => 'owatest-admin-password',
            'timezone'      => 'Europe/Berlin',
        ] ) );

        $this->assertSame( 'Enter a valid email address.', $errors['email_address'] ?? null );
    }

    public function testTheWebInstallerAcceptsAnAddress(): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\InstallBase( [
            'domain'        => 'https://example.test',
            'user_id'       => 'owatest-admin',
            'email_address' => 'alice@example.com',
            'password'      => 'owatest-admin-password',
            'timezone'      => 'Europe/Berlin',
        ] ) );

        $this->assertArrayNotHasKey( 'email_address', $errors );
    }
}
