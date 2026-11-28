<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** AddUserRest whose account creation fails, recording what it was asked to create. */
class UserRoleAndCreateFailingAdd extends \OWA\Module\Base\Controller\AddUserRest
{
    /** @var array<string, mixed>|null */
    public $asked = null;

    protected function createUser( array $user_params )
    {
        $this->asked = $user_params;

        return false;
    }
}

/**
 * The add-user and edit-user screens accept a role a user account may have:
 * not 'everyone', the role of a visitor who is not signed in, and not a name
 * no role has. Adding a user that is not created is answered as an error.
 *
 * No database: the role check stops validation before anything is looked up.
 */
final class UserRoleAndCreateTest extends TestCase
{
    /** @return array<string, mixed> field => message */
    private function validationErrors( \OWA\Core\Controller $controller ): array
    {
        $controller->validate();
        $v = ( new \ReflectionProperty( \OWA\Core\Controller::class, 'v' ) )->getValue( $controller );
        $v->doValidations();

        return (array) $v->getErrorMsgs();
    }

    /** @return array<string, string> */
    private function addParams( string $role ): array
    {
        return [ 'user_id' => 'owatest-role', 'email_address' => 'owatest-role@example.com', 'role' => $role ];
    }

    public function testEveryoneIsNotARoleAUserCanHave(): void
    {
        $this->assertNotContains( 'everyone', \OWA\Core\CoreAPI::getAssignableRoles() );
        $this->assertContains( 'viewer', \OWA\Core\CoreAPI::getAssignableRoles() );
    }

    /** @return array<string, array{0: string}> */
    public static function refusedRoles(): array
    {
        return [ 'everyone' => [ 'everyone' ], 'unknown' => [ 'owatest-no-such-role' ] ];
    }

    /** @dataProvider refusedRoles */
    public function testAddingAUserRefusesTheRole( string $role ): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\UsersAdd( $this->addParams( $role ) ) );

        $this->assertArrayHasKey( 'role', $errors );
        $this->assertStringContainsString( 'viewer', (string) $errors['role'], 'the message names the roles' );
    }

    /** @dataProvider refusedRoles */
    public function testEditingAUserRefusesTheRole( string $role ): void
    {
        $errors = $this->validationErrors( new \OWA\Module\Base\Controller\UsersEdit(
            [ 'user_id' => 'owatest-role', 'email_address' => 'owatest-role@example.com', 'role' => $role ] ) );

        $this->assertArrayHasKey( 'role', $errors );
    }

    public function testTheProfileFormDoesNotOfferEveryone(): void
    {
        // The add form: edit would load a user row.
        $data = [ 'profile' => [], 'edit' => false ];
        $view = \OWA\Core\CoreAPI::viewFactory( 'base.usersProfile' );
        $view->setData( $data );
        $view->render( $data );

        $roles = (array) $view->body->get( 'roles' );

        $this->assertNotContains( 'everyone', $roles );
        $this->assertSame( \OWA\Core\CoreAPI::getAssignableRoles(), $roles );
        $this->assertContains( 'viewer', $roles );
    }

    /** @return array<string, mixed> the add form's body data */
    private function addForm( array $profile = [] ): array
    {
        $data = [ 'profile' => $profile, 'edit' => false ];
        $view = \OWA\Core\CoreAPI::viewFactory( 'base.usersProfile' );
        $view->setData( $data );
        $view->render( $data );

        return [ 'user' => (array) $view->body->get( 'user' ), 'html' => $view->body->fetch() ];
    }

    public function testTheAddFormStartsAtTheLeastPrivilegedRole(): void
    {
        $this->assertSame( 'viewer', \OWA\Core\CoreAPI::leastPrivilegedRole() );

        $form = $this->addForm();

        $this->assertSame( 'viewer', $form['user']['role'] ?? null );
        $this->assertMatchesRegularExpression( '/<option\s+SELECTED\s+value="viewer">/', $form['html'] );
        $this->assertDoesNotMatchRegularExpression( '/<option\s+SELECTED\s+value="admin">/', $form['html'] );
    }

    /** A form drawn again after an error keeps the role that was chosen. */
    public function testTheAddFormKeepsAChosenRole(): void
    {
        $this->assertSame( 'admin', $this->addForm( [ 'role' => 'admin' ] )['user']['role'] ?? null );
    }

    /** Ties go to the role listed last: the setting lists admin first. */
    public function testTheLeastPrivilegedRoleIsTheOneWithFewestCapabilities(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting( 'base', 'capabilities' );

        try {
            \OWA\Core\CoreAPI::setSetting( 'base', 'capabilities', [
                'admin'    => [ 'view_reports', 'edit_users' ],
                'viewer'   => [ 'view_reports', 'edit_reports' ],
                'reader'   => [ 'view_reports' ],
                'everyone' => [],
            ] );

            $this->assertSame( 'reader', \OWA\Core\CoreAPI::leastPrivilegedRole() );
        } finally {
            \OWA\Core\CoreAPI::setSetting( 'base', 'capabilities', $was );
        }
    }

    /** No real_name posted is no real name, and a user not created is an error, not an invitation. */
    public function testAUserNotCreatedIsAnError(): void
    {
        $controller = new UserRoleAndCreateFailingAdd( $this->addParams( 'viewer' ) );

        $data = $controller->action();

        $this->assertSame( '', $controller->asked['real_name'] ?? null );
        $this->assertSame( 422, http_response_code() );
        $this->assertArrayHasKey( 'user_id', (array) ( $data['validation_errors'] ?? [] ) );
        $this->assertArrayNotHasKey( 'invitation_sent', (array) $data );
    }
}
