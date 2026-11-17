<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Delete every stored base.domain_aliases row, at every scope. The setting is
 * gone: a page on another host is stored as it arrived, and a site on several
 * domains is a Property with one Profile per domain.
 *
 * The install row is removed from the settings in memory too, as Update086
 * does: the update run ends with a save, which writes back what the settings
 * still hold.
 *
 * Idempotent: with no row up() changes nothing.
 *
 * down() changes nothing. The values are not kept.
 *
 * RAW SQL on owa_setting, so a test can point the update at a scratch table.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update090 extends \OWA\Core\Update {

    public $schema_version = 90;

    public $down_does_not_restore = 'the Domain Aliases values: the rows are not put back.';

    const NAME = 'domain_aliases';

    /** @var object|null the settings to keep in step instead of the install's. TESTS ONLY. */
    public $settings = null;

    /** The install's settings, or none with a scratch table and no stand-in. */
    private function settings() {

        if ( $this->settings ) {

            return $this->settings;
        }

        return isset( $this->tables['base.setting'] ) ? null : \OWA\Core\CoreAPI::configSingleton();
    }

    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( 'base.setting' );

        $count = (int) ( $db->get_row( sprintf( "SELECT COUNT(*) AS n FROM %s WHERE module = 'base' AND name = ?", $table ),
            array( self::NAME ) )['n'] ?? 0 );

        if ( $count && $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = ?", $table ),
                array( self::NAME ) ) === false ) {

            $this->e->notice( 'Removing the Domain Aliases setting failed.' );

            return false;
        }

        $settings = $this->settings();

        if ( $settings ) {

            $settings->removeSetting( 'base', self::NAME );
        }

        if ( $count ) {

            $this->e->notice( sprintf( 'Domain Aliases: %d %s removed.', $count, \OWA\Core\Lib::plural( $count, 'row', 'rows' ) ) );
        }

        return true;
    }

    public function down() {

        return true;
    }
}

?>
