<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** A UserManager that reads its accounts from an array and records deletes. */
class UserDeleteRuleManager extends \OWA\Module\Base\Classes\UserManager
{
    /** @var array<string, string> user_id => role */
    public $roles = [];

    /** @var list<array{string, string}> user_id, new owner */
    public $deleted = [];

    public function __construct( array $roles )
    {
        $this->roles = $roles;
    }

    protected function roleOf( $user_id )
    {
        return $this->roles[ $user_id ] ?? '';
    }

    protected function adminCount()
    {
        return count( array_keys( $this->roles, 'admin', true ) );
    }

    public function deleteUser( $user_id, $new_owner = '' )
    {
        $this->deleted[] = [ (string) $user_id, (string) $new_owner ];

        return true;
    }
}

/** base.usersDelete on a UserDeleteRuleManager. */
class UserDeleteRuleDelete extends \OWA\Module\Base\Controller\UsersDelete
{
    /** @var UserDeleteRuleManager */
    public static $manager;

    protected function userManager()
    {
        return self::$manager;
    }
}

/** DELETE base/v1/users on a UserDeleteRuleManager. */
class UserDeleteRuleRest extends \OWA\Module\Base\Controller\DeleteUserRest
{
    protected function userManager()
    {
        return UserDeleteRuleDelete::$manager;
    }
}

/**
 * One rule, on the Users screen's Delete, REST DELETE base/v1/users and the
 * Edit screen's role: nobody deletes their own account, and the last account
 * with the admin role is neither deleted nor given another role.
 *
 * No database: the accounts are UserDeleteRuleManager's.
 */
final class UserDeleteRuleTest extends TestCase
{
    protected function setUp(): void
    {
        $this->signInAs( 'alice' );
    }

    protected function tearDown(): void
    {
        $this->signInAs( '' );
        http_response_code( 200 );
    }

    private function signInAs( string $user_id ): void
    {
        $u = new \OWA\Module\Base\Entity\User();
        $u->set( 'user_id', $user_id );
        $u->set( 'role', $user_id === '' ? 'everyone' : 'admin' );

        \OWA\Core\CoreAPI::getCurrentUser()->loadNewUserByObject( $u );
    }

    /** @return array<string, mixed> */
    private function delete( string $class, string $user_id, array $roles ): array
    {
        UserDeleteRuleDelete::$manager = new UserDeleteRuleManager( $roles );

        $c = new $class( [ 'user_id' => $user_id ] );

        return (array) ( $c->action() ?? $c->data );
    }

    public function testYouCannotDeleteYourOwnAccount(): void
    {
        $data = $this->delete( UserDeleteRuleDelete::class, 'alice', [ 'alice' => 'admin', 'bob' => 'admin' ] );

        $this->assertSame( [], UserDeleteRuleDelete::$manager->deleted );
        $this->assertSame( 3015, $data['error_code'] ?? null );
        $this->assertSame( 'base.users', $data['do'] ?? null );
        $this->assertArrayNotHasKey( 'status_code', $data, 'not "User account deleted."' );
    }

    public function testTheOnlyAdminCannotBeDeleted(): void
    {
        $data = $this->delete( UserDeleteRuleDelete::class, 'bob', [ 'alice' => 'viewer', 'bob' => 'admin' ] );

        $this->assertSame( [], UserDeleteRuleDelete::$manager->deleted );
        $this->assertSame( 3016, $data['error_code'] ?? null );
    }

    public function testAnAdminIsDeletedWhileAnotherRemains(): void
    {
        $this->delete( UserDeleteRuleDelete::class, 'bob', [ 'alice' => 'admin', 'bob' => 'admin' ] );

        $this->assertSame( [ [ 'bob', 'alice' ] ], UserDeleteRuleDelete::$manager->deleted,
            'deleted, and what they shared passes to the admin who deleted them' );
    }

    public function testAnotherRoleIsDeleted(): void
    {
        $this->delete( UserDeleteRuleDelete::class, 'bob', [ 'alice' => 'admin', 'bob' => 'viewer' ] );

        $this->assertSame( [ [ 'bob', 'alice' ] ], UserDeleteRuleDelete::$manager->deleted );
    }

    /** @return array<string, array{string, array<string, string>, int}> */
    public static function restRefusals(): array
    {
        return [
            'your own account' => [ 'alice', [ 'alice' => 'admin', 'bob' => 'admin' ], 3015 ],
            'the only admin'   => [ 'bob', [ 'alice' => 'analyst', 'bob' => 'admin' ], 3016 ],
        ];
    }

    /** @dataProvider restRefusals */
    public function testTheRestRouteRefusesTheSame( string $user_id, array $roles, int $code ): void
    {
        $data = $this->delete( UserDeleteRuleRest::class, $user_id, $roles );

        $this->assertSame( [], UserDeleteRuleDelete::$manager->deleted );
        $this->assertSame( 422, http_response_code() );
        $this->assertSame( ( new \OWA\Core\Base() )->getMsg( $code )['message'], $data['validation_errors']['user_id'] ?? null );
    }

    public function testTheMessages(): void
    {
        $base = new \OWA\Core\Base();

        $this->assertSame( 'You cannot delete your own account.', $base->getMsg( 3015 )['message'] );
        $this->assertSame( 'The only admin account cannot be deleted. Give another user the admin role first.', $base->getMsg( 3016 )['message'] );
        $this->assertSame( 'The only admin account keeps the admin role. Give another user the admin role first.', $base->getMsg( 3017 )['message'] );
    }

    /** @return array<string, array{string, string, int, int}> */
    public static function roleChanges(): array
    {
        return [
            'the only admin, demoted'      => [ 'admin', 'viewer', 1, 3017 ],
            'the only admin, kept admin'   => [ 'admin', 'admin', 1, 0 ],
            'one of two admins, demoted'   => [ 'admin', 'analyst', 2, 0 ],
            'a viewer, made admin'         => [ 'viewer', 'admin', 1, 0 ],
            'a viewer, made analyst'       => [ 'viewer', 'analyst', 1, 0 ],
        ];
    }

    /** @dataProvider roleChanges */
    public function testTheOnlyAdminKeepsTheRole( string $current, string $role, int $admins, int $code ): void
    {
        $this->assertSame( $code, \OWA\Module\Base\Classes\UserManager::roleChangeRefusalOf( $current, $role, $admins ) );
    }

    public function testRoleChangeRefusalReadsTheAccount(): void
    {
        $um = new UserDeleteRuleManager( [ 'alice' => 'admin', 'bob' => 'viewer' ] );

        $this->assertSame( 3017, $um->roleChangeRefusal( 'alice', 'viewer' ) );
        $this->assertSame( 0, $um->roleChangeRefusal( 'bob', 'analyst' ) );

        $um->roles['carol'] = 'admin';

        $this->assertSame( 0, $um->roleChangeRefusal( 'alice', 'viewer' ) );
    }

    /** The list offers Delete on every account but your own, the first one included. */
    public function testTheListOffersNoDeleteForYourOwnAccount(): void
    {
        $t = new \OWA\Core\Template( 'base' );
        $t->set( 'headline', 'User Roster' );
        $t->set( 'current_user_id', 'bob' );
        $t->set( 'users', [
            [ 'id' => 1, 'user_id' => 'alice', 'real_name' => 'Alice', 'role' => 'admin' ],
            [ 'id' => 2, 'user_id' => 'bob', 'real_name' => 'Bob', 'role' => 'admin' ],
        ] );
        $this->assertTrue( $t->set_template( 'users.php' ) );

        $html = $t->fetch();

        $links = $this->deleteLinks( $html );

        $this->assertStringContainsString( 'user_id=alice', $links, 'row id 1 has one' );
        $this->assertStringNotContainsString( 'user_id=bob', $links );
    }

    /** The Delete links' hrefs, decoded, one per line. */
    private function deleteLinks( string $html ): string
    {
        preg_match_all( '/href="([^"]*usersDelete[^"]*)"/', $html, $m );

        return implode( "\n", array_map( fn ( $href ) => urldecode( html_entity_decode( $href ) ), $m[1] ) );
    }

    /** Neither the installer's real name nor row id 1 is a special case any more. */
    public function testNoAccountIsProtectedByNameOrId(): void
    {
        $edit = (string) file_get_contents( dirname( __DIR__ ) . '/modules/Base/Controller/UsersEdit.php' );
        $view = (string) file_get_contents( dirname( __DIR__ ) . '/modules/Base/View/UsersProfile.php' );
        $list = (string) file_get_contents( dirname( __DIR__ ) . '/modules/Base/templates/users.php' );

        $this->assertStringNotContainsString( 'isOWAAdmin', $edit );
        $this->assertStringNotContainsString( 'isOWAAdmin', $view );
        $this->assertStringNotContainsString( '$value[\'id\'] != 1', $list );
    }
}
