<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * enableEcommerceReporting moves from the Profile to the Property.
 *
 * A report is of a Property and covers every Profile in it, so a per-Profile
 * switch has nothing to switch. The setting no longer declares the profile
 * scope, and a row left there would be read by nothing.
 *
 * Each Property whose Profiles stored a value is set to whether ANY of its
 * Profiles had it on -- its own value, or else the one it inherited -- since
 * that is the reading under which no Profile loses its e-commerce reports. That
 * can overwrite a value the Property held, which its Profiles had overridden. A
 * Profile with no Property has nowhere to go and is only removed.
 *
 * ONE-WAY, as Update046 is: no schema changes here, and keeping the Profile
 * rows somewhere for a rollback would put a table or column on an upgraded
 * install that a fresh one does not have. down() puts the setting back where
 * the code before this reads it -- each Profile without a value of its own takes
 * its Property's -- so every Profile answers as it did. A Profile that differed
 * from its siblings before up() does not get its own value back.
 *
 * Idempotent both ways. Raw SQL throughout, so the settings entity cache cannot
 * answer for a row this has just changed.
 */
class Update070 extends \OWA\Core\Update {

    var $schema_version = 70;

    var $is_cli_mode_required = false;

    const NAME = 'enableEcommerceReporting';

    /** @var string|null the settings table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        $held = array();

        foreach ( (array) $db->get_results( sprintf(
                "SELECT scope_id, value FROM %s WHERE scope_type = 'profile' AND module = 'base' AND name = ?", $table ),
                array( self::NAME ) ) as $row ) {

            $row = (array) $row;

            // NULL is no value: that Profile inherits, like one with no row.
            $held[ (string) $row['scope_id'] ] = @unserialize( (string) $row['value'] );
        }

        $properties = array();

        foreach ( array_keys( $held ) as $site_id ) {

            $property = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( (string) $site_id );

            if ( $property !== '' ) {

                $properties[ $property ] = true;
            }
        }

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        foreach ( array_keys( $properties ) as $property ) {

            $property = (string) $property;
            $own      = $db->get_row( sprintf(
                "SELECT value FROM %s WHERE scope_type = 'property' AND scope_id = ? AND module = 'base' AND name = ?", $table ),
                array( $property, self::NAME ) );

            $inherited = $own
                ? (bool) @unserialize( (string) ( (array) $own )['value'] )
                : (bool) \OWA\Core\CoreAPI::getSetting( 'base', self::NAME );

            $on = false;

            foreach ( \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $property ) as $site_id ) {

                $value = $held[ (string) $site_id ] ?? null;

                $on = $on || ( $value === null ? $inherited : (bool) $value );
            }

            if ( $db->query( sprintf( 'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
                    . " VALUES (?, 'property', ?, 'base', ?, ?, 1, ?) ON DUPLICATE KEY UPDATE value = ?", $table ),
                    array( $setting->makeId( 'property', $property, 'base', self::NAME ), $property, self::NAME,
                        serialize( $on ), time(), serialize( $on ) ) ) === false ) {

                $this->e->notice( sprintf( 'Moving %s to Property %s failed.', self::NAME, $property ) );

                return false;
            }
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE scope_type = 'profile' AND module = 'base' AND name = ?",
                $table ), array( self::NAME ) ) === false ) {

            $this->e->notice( sprintf( 'Removing the Profile rows of %s failed.', self::NAME ) );

            return false;
        }

        return true;
    }

    /** Each Property's value written to those of its Profiles that hold none. */
    function down() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $table   = $this->table();
        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $rows = (array) $db->get_results( sprintf(
            "SELECT * FROM %s WHERE scope_type = 'property' AND module = 'base' AND name = ?", $table ),
            array( self::NAME ) );

        foreach ( $rows as $row ) {

            $row = (array) $row;

            foreach ( \OWA\Module\Base\Classes\Cube\Cubes::siteIds( (string) $row['scope_id'] ) as $site_id ) {

                if ( $db->query( sprintf( 'INSERT IGNORE INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
                        . " VALUES (?, 'profile', ?, 'base', ?, ?, ?, ?)", $table ),
                        array( $setting->makeId( 'profile', $site_id, 'base', self::NAME ), $site_id, self::NAME,
                            $row['value'], $row['autoload'], time() ) ) === false ) {

                    $this->e->notice( sprintf( 'Restoring %s to Profile %s failed.', self::NAME, $site_id ) );

                    return false;
                }
            }
        }

        return true;
    }
}

?>
