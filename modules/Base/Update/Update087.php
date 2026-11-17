<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_user.session_epoch: signed into every session cookie, and moved by a
 * sign-out or a password change to end all of a user's sessions (see
 * Auth::sessionCredential(), User::endSessions()).
 *
 * up() adds the column, 0 for every user, unless it is there. down() drops it.
 *
 * Session cookies issued before this update do not have the new form and sign
 * nobody in: every user signs in again.
 *
 * NOT CLI-ONLY. One small table.
 */
class Update087 extends \OWA\Core\Update {

    public $schema_version = 87;

    const COLUMN = 'session_epoch';

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not (see Update063).
     */
    const DEFINITION = 'INT NOT NULL DEFAULT 0';

    private function hasColumn() {

        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf( "SHOW COLUMNS FROM %s LIKE '%s'", $this->tableFor( 'base.user' ), self::COLUMN ) );

        return ! empty( $rows );
    }

    public function up( $force = false ) {

        if ( $this->hasColumn() ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s ADD COLUMN %s %s',
                $this->tableFor( 'base.user' ), self::COLUMN, self::DEFINITION ) ) === false ) {

            $this->e->notice( sprintf( 'Adding %s to %s failed.', self::COLUMN, $this->tableFor( 'base.user' ) ) );

            return false;
        }

        return true;
    }

    /** The exact inverse. */
    public function down() {

        if ( ! $this->hasColumn() ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s DROP COLUMN %s',
                $this->tableFor( 'base.user' ), self::COLUMN ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s from %s failed.', self::COLUMN, $this->tableFor( 'base.user' ) ) );

            return false;
        }

        return true;
    }
}

?>
