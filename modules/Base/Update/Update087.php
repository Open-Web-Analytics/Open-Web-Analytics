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

    var $schema_version = 87;

    var $is_cli_mode_required = false;

    const COLUMN = 'session_epoch';

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not (see Update063).
     */
    const DEFINITION = 'INT NOT NULL DEFAULT 0';

    /** @var string|null the user table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName();
    }

    private function hasColumn() {

        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf( "SHOW COLUMNS FROM %s LIKE '%s'", $this->table(), self::COLUMN ) );

        return ! empty( $rows );
    }

    function up( $force = false ) {

        if ( $this->hasColumn() ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s ADD COLUMN %s %s',
                $this->table(), self::COLUMN, self::DEFINITION ) ) === false ) {

            $this->e->notice( sprintf( 'Adding %s to %s failed.', self::COLUMN, $this->table() ) );

            return false;
        }

        return true;
    }

    /** The exact inverse. */
    function down() {

        if ( ! $this->hasColumn() ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s DROP COLUMN %s',
                $this->table(), self::COLUMN ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s from %s failed.', self::COLUMN, $this->table() ) );

            return false;
        }

        return true;
    }
}

?>
