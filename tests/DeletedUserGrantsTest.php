<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Deleting a user takes their site access with them.
 *
 * Nothing reads grants in that direction -- every screen lists users and looks
 * their grants up -- and Property Access Management submits a delta of the
 * users it RENDERED, so a grant left keyed on a deleted user would never be
 * cleared.
 *
 * Not quite inert, either. User ids are auto-increment rather than derived from
 * the username, so re-creating a deleted username does not inherit its access
 * -- but a restore from backup can reset the counter to MAX(id)+1, and a new
 * user minted onto a deleted user's id would pick up whatever it was granted.
 */
final class DeletedUserGrantsTest extends TestCase
{
    private string $userId = '';
    private string $siteId = '';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id' );

        $row = $db->getOneRow();

        if ( empty( $row['id'] ) ) {
            $this->markTestSkipped( 'Needs a site to grant access to.' );
        }

        $this->siteId = $row['id'];
        $this->userId = 'grant-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    }

    protected function tearDown(): void
    {
        if ( ! $this->userId ) {
            return;
        }

        $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $user->load( $this->userId, 'user_id' );

        if ( $user->wasPersisted() ) {

            $this->deleteGrantsFor( $user->get( 'id' ) );
            $user->delete( $this->userId, 'user_id' );
        }

        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
    }

    private function deleteGrantsFor( $id ): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( 'owa_site_user' );
        $db->where( 'user_id', $id );
        $db->executeQuery();
    }

    private function grantCountFor( $id ): int
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( 'owa_site_user' );
        $db->selectColumn( 'user_id' );
        $db->where( 'user_id', $id );

        return count( (array) $db->getAllRows() );
    }

    /** Make a user with one grant, and hand back their row id. */
    private function makeGrantedUser(): string
    {
        $um = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' );

        $created = $um->createNewUser( [
            'user_id'       => $this->userId,
            'role'          => 'viewer',
            'password'      => 'probe-' . uniqid( '', true ),
            'email_address' => $this->userId . '@example.test',
            'real_name'     => 'Grant Probe',
        ] );

        $this->assertNotFalse( $created, 'could not create the probe user' );

        $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $user->load( $this->userId, 'user_id' );

        $this->assertTrue( $user->wasPersisted(), 'the probe user was not stored' );

        $grant = \OWA\Core\CoreAPI::entityFactory( 'base.site_user' );
        $grant->set( 'user_id', $user->get( 'id' ) );
        $grant->set( 'site_id', $this->siteId );
        $grant->create();

        $this->assertSame( 1, $this->grantCountFor( $user->get( 'id' ) ),
            'the grant was not stored, so this test could not detect it being left behind' );

        return (string) $user->get( 'id' );
    }

    public function testDeletingAUserRevokesTheirSiteAccess(): void
    {
        $rowId = $this->makeGrantedUser();

        $um = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' );

        $this->assertTrue( $um->deleteUser( $this->userId ) );

        $this->assertSame( 0, $this->grantCountFor( $rowId ),
            'The user is gone but their site access is not, so the grant sits on a user '
            . 'row that no longer exists and no screen can reach it.' );
    }

    /** And the user really is deleted -- the grant cleanup must not replace it. */
    public function testTheUserIsStillDeleted(): void
    {
        $this->makeGrantedUser();

        $um = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' );
        $um->deleteUser( $this->userId );

        $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $user->load( $this->userId, 'user_id' );

        // Cast: wasPersisted() answers null -- not false -- for a row that was
        // never found, so a strict assertFalse fails on a user that really is
        // gone. Falsy is the question being asked.
        $this->assertFalse( (bool) $user->wasPersisted(),
            'Deleting the grants stopped the user themselves being deleted.' );
    }

    /**
     * Only THEIR access. A revoke that took everyone's would be far worse than
     * the leak it replaces.
     */
    public function testItRevokesOnlyThatUsersAccess(): void
    {
        $before = 0;

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( 'owa_site_user' );
        $db->selectColumn( 'user_id' );
        $before = count( (array) $db->getAllRows() );

        $this->makeGrantedUser();

        $um = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' );
        $um->deleteUser( $this->userId );

        $db->selectFrom( 'owa_site_user' );
        $db->selectColumn( 'user_id' );

        $this->assertSame( $before, count( (array) $db->getAllRows() ),
            'The grant table did not come back to where it started, so the delete took '
            . 'either too little or far too much.' );
    }

    /**
     * The assigned-users cache is keyed by SITE, and this revokes across every
     * site at once -- so there is no single key to evict and the whole map goes.
     */
    public function testTheAssignedUsersCacheIsCleared(): void
    {
        $rowId = $this->makeGrantedUser();

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $this->siteId );

        $assigned = function () use ( $site ): array {

            return array_map( static function ( $user ) {

                return (string) $user->get( 'id' );
            }, $site->getAssignedUsers() );
        };

        // Read once, so the site's list is cached for the rest of the request.
        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
        $this->assertContains( $rowId, $assigned() );

        \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' )->deleteUser( $this->userId );

        $this->assertNotContains( $rowId, $assigned(),
            'A deleted user can still appear in a site\'s assigned-users list for the '
            . 'rest of the request.' );
    }
}
