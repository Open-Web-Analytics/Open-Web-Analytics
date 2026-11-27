<?php
namespace OWA\Module\Base\Classes;

/**
 * User Manager Class
 * 
 * handels the common tasks associated with creating and manipulating user accounts
 */

class UserManager extends \OWA\Core\Base {

    /**
     * The conf/messages.php codes a refused delete or role change answers
     * with (deleteRefusal(), roleChangeRefusal()).
     */
    const REFUSED_OWN_ACCOUNT       = 3015;
    const REFUSED_DELETE_LAST_ADMIN = 3016;
    const REFUSED_DEMOTE_LAST_ADMIN = 3017;

    /**
     * Why $acting may not delete $user_id, as a message code, or 0. Nobody
     * deletes their own account, and the last account with the admin role
     * is not deleted.
     *
     * @param  string $user_id
     * @param  string $acting the signed-in user's user_id
     * @return int
     */
    public function deleteRefusal( $user_id, $acting ) {

        if ( (string) $user_id === (string) $acting ) {

            return self::REFUSED_OWN_ACCOUNT;
        }

        $role = $this->roleOf( $user_id );

        return self::deleteRefusalOf( (string) $user_id, (string) $acting, $role,
            $role === \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE ? $this->adminCount() : 0 );
    }

    /**
     * deleteRefusal() on what it read.
     *
     * @param  string $user_id
     * @param  string $acting
     * @param  string $role   $user_id's role
     * @param  int    $admins how many accounts have the admin role
     * @return int
     */
    public static function deleteRefusalOf( $user_id, $acting, $role, $admins ) {

        if ( (string) $user_id === (string) $acting ) {

            return self::REFUSED_OWN_ACCOUNT;
        }

        if ( $role === \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE && $admins <= 1 ) {

            return self::REFUSED_DELETE_LAST_ADMIN;
        }

        return 0;
    }

    /**
     * Why $user_id may not be given $role, as a message code, or 0: the last
     * account with the admin role keeps it.
     *
     * @param  string $user_id
     * @param  string $role
     * @return int
     */
    public function roleChangeRefusal( $user_id, $role ) {

        $current = $this->roleOf( $user_id );

        return self::roleChangeRefusalOf( $current, (string) $role,
            $current === \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE ? $this->adminCount() : 0 );
    }

    /**
     * roleChangeRefusal() on what it read.
     *
     * @param  string $current the account's role now
     * @param  string $role    the role asked for
     * @param  int    $admins  how many accounts have the admin role
     * @return int
     */
    public static function roleChangeRefusalOf( $current, $role, $admins ) {

        $admin = \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE;

        if ( $current === $admin && (string) $role !== $admin && $admins <= 1 ) {

            return self::REFUSED_DEMOTE_LAST_ADMIN;
        }

        return 0;
    }

    /**
     * A stored account's role, '' for none. Read with its own SQL: the
     * entity cache could answer with a role since changed.
     *
     * @param  string $user_id
     * @return string
     */
    protected function roleOf( $user_id ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT role FROM %s WHERE user_id = ?',
            \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() ), [ (string) $user_id ] );

        return is_array( $row ) ? (string) ( $row['role'] ?? '' ) : '';
    }

    /**
     * How many accounts have the admin role.
     *
     * @return int
     */
    protected function adminCount() {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT COUNT(*) AS admins FROM %s WHERE role = ?',
            \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() ), [ \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE ] );

        return is_array( $row ) ? (int) ( $row['admins'] ?? 0 ) : 0;
    }
    /**
     * @param  array<string, mixed> $user_params user_id, role, email_address, real_name, and password when one is set
     * @return \OWA\Module\Base\Entity\User|false
     */
    public function createNewUser($user_params) {

        if ( isset( $user_params['password'] ) ) {
            $password = $user_params['password'];
        } else {
            $password = '';
        }

        // save new user to db
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $ret = $u->createNewUser(
                $user_params['user_id'],
                $user_params['role'],
                $password,
                $user_params['email_address'],
                $user_params['real_name']
        );

        if ( $ret ) {
            return $u;
        } else {
            return false;
        }

    }

    /**
     * @param  array<string, mixed> $user_params password, and temp_passkey or user_id
     * @return \OWA\Module\Base\Entity\User|false
     */
    public function updateUserPassword($user_params)
    {
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');

        if (!isset($user_params['temp_passkey']) && !isset($user_params['user_id'])) {
            \OWA\Core\CoreAPI::error( "No user identification given!" );
            return false;
        }

        // The screens and commands refuse it first, with a message.
        if ( \OWA\Module\Base\Entity\User::passwordIsTooLong( $user_params['password'] ?? '' ) ) {

            \OWA\Core\CoreAPI::error( sprintf( 'Password not changed: it is longer than %d bytes.', \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES ) );

            return false;
        }

        if (isset($user_params['temp_passkey'])) {
            $u->getByColumn('temp_passkey', $user_params['temp_passkey']);
        }

        if (isset($user_params['user_id'])) {
            $u->getByColumn('user_id', $user_params['user_id']);
        }

        // The link that set it cannot set it again.
        $u->set('temp_passkey', \OWA\Module\Base\Entity\User::spentTempPasskey() );
        $u->set('password', \OWA\Core\Lib::encryptPassword($user_params['password']));
        // A new password signs out every session, on every device.
        $u->endSessions();
        $ret = $u->update();

        // However it was changed -- a reset link, My Profile, the CLI.
        if ( $ret ) {

            AppPasswords::askForReview( (string) $u->get( 'user_id' ) );
        }

        return $ret ? $u : false;

    }

    /**
     * Delete a user, and everything stored for them alone: their site access,
     * application passwords, report overrides, favourites, private custom
     * reports and funnels, and the notifications addressed to them and what
     * they did with others. A custom report or funnel they shared stays on
     * everyone's list, and passes to $new_owner.
     *
     * base.usersDelete and DELETE base/v1/users both come here; whether the
     * account may be deleted is deleteRefusal()'s answer, asked first.
     *
     * Rows keyed by the user_id are keyed by the user name, so anything left
     * would belong to the next account given that name. Grants are keyed by
     * the row id, which a restore from backup can hand out again.
     *
     * The per-user rows go first: if they go and the user delete then fails,
     * the account remains without them, which is recoverable. The other order
     * can leave rows attached to nothing.
     *
     * @param  string $user_id
     * @param  string $new_owner the user_id that is given their shared reports:
     *                           the admin deleting them; another admin when ''
     * @return bool
     */
    public function deleteUser( $user_id, $new_owner = '' ) {

        $user_id = (string) $user_id;

        // An empty name matches the rows that belong to nobody: global notifications.
        if ( $user_id === '' ) {

            return false;
        }

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->load( $user_id, 'user_id' );

        if ( $u->wasPersisted() ) {

            $this->deleteSiteAccessFor( $u->get('id') );
        }

        // Auth refuses a password whose user is missing, which covers only
        // the time in between.
        \OWA\Module\Base\Classes\AppPasswords::revokeAllForUser( $user_id );

        $this->deleteReportsOf( $user_id, (string) $new_owner );
        $this->deleteRowsOf( 'base.custom_report_favorite', $user_id );
        $this->deleteNotificationsOf( $user_id );

        $ret = $u->delete($user_id, 'user_id');

        if ( $ret ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * A user's custom reports and funnels, and their versions of shipped
     * reports. Shared ones pass to $new_owner, else to another admin; the
     * rest are deleted, with every favourite of them.
     *
     * @param  string $user_id
     * @param  string $new_owner
     * @return void
     */
    private function deleteReportsOf( $user_id, $new_owner ) {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' )->getTableName();
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        $rows = (array) $db->get_results( sprintf( 'SELECT id, is_shared, replaces FROM %s WHERE user_id = ?', $table ), [ $user_id ] );

        if ( ! $rows ) {

            return;
        }

        $owner = $new_owner !== '' && $new_owner !== $user_id ? $new_owner : $this->anotherAdmin( $user_id );

        foreach ( $rows as $row ) {

            if ( self::keepsReport( $row ) ) {

                $db->query( sprintf( 'UPDATE %s SET user_id = ? WHERE id = ?', $table ), [ $owner, (string) $row['id'] ] );

                continue;
            }

            \OWA\Module\Base\Classes\CustomReports::delete( (string) $row['id'] );
        }
    }

    /**
     * Whether a deleted user's custom_report row stays, for a new owner: a
     * shared report or funnel. A version of a shipped report never does.
     *
     * @param  array<string, mixed> $row id, is_shared, replaces
     * @return bool
     */
    public static function keepsReport( array $row ) {

        return (string) ( $row['replaces'] ?? '' ) === '' && (bool) ( $row['is_shared'] ?? false );
    }

    /**
     * The first admin by row id that is not $user_id, '' when there is none.
     *
     * @param  string $user_id
     * @return string
     */
    protected function anotherAdmin( $user_id ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT user_id FROM %s WHERE role = ? AND user_id <> ? ORDER BY id LIMIT 1',
            \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() ),
            [ \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE, (string) $user_id ] );

        return is_array( $row ) ? (string) ( $row['user_id'] ?? '' ) : '';
    }

    /**
     * The notifications addressed to one user, and every read or dismissed
     * mark they made.
     *
     * @param  string $user_id
     * @return void
     */
    private function deleteNotificationsOf( $user_id ) {

        $states = \OWA\Core\CoreAPI::entityFactory( 'base.notification_state' )->getTableName();
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        // Others' marks on a notification addressed to this user alone.
        $db->query( sprintf( 'DELETE FROM %s WHERE notification_id IN (SELECT id FROM %s WHERE user_id = ?)',
            $states, \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->getTableName() ), [ $user_id ] );

        $this->deleteRowsOf( 'base.notification_state', $user_id );
        $this->deleteRowsOf( 'base.notification', $user_id );
    }

    /**
     * Every row of one entity's table whose user_id is $user_id.
     *
     * @param  string $entity
     * @param  string $user_id never '': deleteUser() refuses it
     * @return void
     */
    private function deleteRowsOf( $entity, $user_id ) {

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DELETE FROM %s WHERE user_id = ?',
            \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName() ), [ (string) $user_id ] );
    }

    /**
     * Remove every site grant held by one user row.
     *
     * @param  int|string $id  the user's own row id, not their user_id
     * @return void
     */
    private function deleteSiteAccessFor( $id ) {

        if ( ! $id ) {

            return;
        }

        $siteUser = \OWA\Core\CoreAPI::entityFactory('base.site_user');

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $siteUser->getTableName() );
        $db->where( 'user_id', $id );
        $db->executeQuery();

        // The assigned-users cache is keyed by SITE, and this revoked across
        // all of them at once, so there is no single key to evict.
        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
    }
}
