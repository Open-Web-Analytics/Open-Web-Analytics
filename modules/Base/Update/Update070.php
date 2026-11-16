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
 * THE INVERSE. Before anything changes, the Profile rows, every column, and
 * each Property row as it was before up() wrote it (null when there was none)
 * are kept in the ecommerce_reporting_before_070 setting. down() puts the
 * Profile rows back, puts each Property row back or removes it if up() created
 * it, and removes the record. A run of up() that finds no Profile row changes
 * nothing, so a second run leaves the record of the first.
 *
 * An installation that ran up() before the record was kept has none. down()
 * then puts the setting back where the code before this reads it -- each
 * Profile without a value of its own takes its Property's -- so every Profile
 * answers as it did, and a Profile that differed from its siblings does not get
 * its own value back.
 *
 * Idempotent both ways. Raw SQL throughout, record included, so the settings
 * entity cache cannot answer for a row this has just changed.
 */
class Update070 extends \OWA\Core\Update {

    var $schema_version = 70;

    var $is_cli_mode_required = false;

    const NAME = 'enableEcommerceReporting';

    var $down_does_not_restore = 'on an installation that ran it before it kept ecommerce_reporting_before_070, each Profile\'s own value: every Profile takes its Property\'s.';

    /** What up() did: { profiles: id => row, properties: property id => row|null }. */
    const BEFORE = 'ecommerce_reporting_before_070';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** @var string|null the settings table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        $held = array();
        $rows = array();

        foreach ( (array) $db->get_results( sprintf(
                "SELECT * FROM %s WHERE scope_type = 'profile' AND module = 'base' AND name = ?", $table ),
                array( self::NAME ) ) as $row ) {

            $row = (array) $row;
            $rows[ (string) $row['id'] ] = $row;

            // NULL is no value: that Profile inherits, like one with no row.
            $held[ (string) $row['scope_id'] ] = @unserialize( (string) $row['value'] );
        }

        if ( ! $rows ) {

            return true;
        }

        $properties = array();

        foreach ( array_keys( $held ) as $site_id ) {

            $property = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( (string) $site_id );

            if ( $property !== '' ) {

                $properties[ $property ] = true;
            }
        }

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        // The first run's copy is the one down() restores.
        $record = $this->record();
        $record['profiles']   = ( isset( $record['profiles'] ) && is_array( $record['profiles'] ) ? $record['profiles'] : array() ) + $rows;
        $record['properties'] = isset( $record['properties'] ) && is_array( $record['properties'] ) ? $record['properties'] : array();

        foreach ( array_keys( $properties ) as $property ) {

            if ( ! array_key_exists( (string) $property, $record['properties'] ) ) {

                $record['properties'][ (string) $property ] = $this->row( 'property', (string) $property );
            }
        }

        // Kept before anything changes, so a run that stops part way can still be undone.
        if ( ! $this->writeRecord( serialize( $record ) ) ) {

            $this->e->notice( sprintf( 'Keeping the Profile rows of %s in %s failed.', self::NAME, self::BEFORE ) );

            return false;
        }

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

    /** The exact inverse, from the record up() kept; without one, see approximately(). */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $record = $this->record();

        if ( ! $record ) {

            return $this->approximately();
        }

        foreach ( (array) ( $record['profiles'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring %s row %s failed.', self::NAME, $row['id'] ?? '' ) );

                return false;
            }
        }

        foreach ( (array) ( $record['properties'] ?? array() ) as $property => $row ) {

            $restored = $row === null
                ? $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->table() ),
                    array( $this->settingId( 'property', (string) $property, self::NAME ) ) ) !== false
                : $this->restoreRow( (array) $row );

            if ( ! $restored ) {

                $this->e->notice( sprintf( 'Restoring %s on Property %s failed.', self::NAME, $property ) );

                return false;
            }
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->table() ),
                array( $this->settingId( 'install', self::INSTALL_SCOPE_ID, self::BEFORE ) ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /**
     * With no record: each Property's value written to those of its Profiles
     * that hold none, so every Profile answers as it did.
     */
    private function approximately() {

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

    /** What up() kept, or an empty array. */
    private function record() {

        $row = $this->row( 'install', self::INSTALL_SCOPE_ID, self::BEFORE );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    /** A row of base.<name> at this scope, every column, or null. */
    private function row( $scope_type, $scope_id, $name = self::NAME ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT * FROM %s WHERE id = ?', $this->table() ),
            array( $this->settingId( $scope_type, $scope_id, $name ) ) );

        return $row ? (array) $row : null;
    }

    private function settingId( $scope_type, $scope_id, $name ) {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId( $scope_type, (string) $scope_id, 'base', $name );
    }

    /** Upsert the install row of the record. */
    private function writeRecord( $value ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, 0, ?) ON DUPLICATE KEY UPDATE value = ?", $this->table() ),
            array( $this->settingId( 'install', self::INSTALL_SCOPE_ID, self::BEFORE ), self::INSTALL_SCOPE_ID, self::BEFORE,
                $value, time(), $value ) ) !== false;
    }

    /** A row put back exactly as SELECT * read it. */
    private function restoreRow( array $row ) {

        $columns = array_keys( $row );

        foreach ( $columns as $column ) {

            // Names the table gave, but they are interpolated, so only plain ones.
            if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {

                return false;
            }
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->table() ), array( (string) $row['id'] ) ) === false ) {

            return false;
        }

        return $db->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->table(), implode( ', ', $columns ),
            implode( ', ', array_fill( 0, count( $columns ), '?' ) ) ), array_values( $row ) ) !== false;
    }
}

?>
