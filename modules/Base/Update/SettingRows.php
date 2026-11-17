<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Raw SQL on install rows of owa_setting, for an update that keeps what it
 * changes in a record setting so down() can put it back.
 *
 * For a \OWA\Core\Update. The using class defines INSTALL_SCOPE_ID and BEFORE,
 * the name of the record setting. The table is tableFor( 'base.setting' ), so
 * a test can point the update at a scratch table through $tables.
 */
trait SettingRows {

    /** @var object|null the settings to keep in step instead of the install's. TESTS ONLY. */
    public $settings = null;

    /**
     * @return string
     */
    private function settingTable() {

        return $this->tableFor( 'base.setting' );
    }

    /**
     * The settings this process saves, which must agree with the table.
     *
     * The update run saves its settings when it ends. That save prunes an
     * eager install row the settings do not hold, and writes back a row they
     * still hold, so a row changed in the table only is changed back.
     *
     * None with a scratch table and no stand-in: the install's own settings
     * are not the ones being changed.
     *
     * @return object|null the install's Settings, or a test's stand-in
     */
    private function settings() {

        if ( $this->settings ) {

            return $this->settings;
        }

        return isset( $this->tables['base.setting'] ) ? null : \OWA\Core\CoreAPI::configSingleton();
    }

    /**
     * Hold this value of base.<name> in the settings, or nothing when it is null.
     *
     * @param  string $name
     * @param  mixed  $value a setting's value, any type
     * @return void
     */
    private function hold( $name, $value ) {

        $settings = $this->settings();

        if ( ! $settings ) {

            return;
        }

        if ( $value === null ) {

            $settings->removeSetting( 'base', $name );

            return;
        }

        $settings->db_settings['base'][ $name ] = $value;
        $settings->set( 'base', $name, $value );
    }

    /**
     * What up() kept, or an empty array.
     *
     * @return array<mixed> unserialized from the table, so its shape is unchecked
     */
    private function record() {

        $row = $this->row( self::BEFORE );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    /**
     * An install row of base.<name>, every column, or null.
     *
     * @param  string $name
     * @return array<string, scalar|null>|null
     */
    private function row( $name ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT * FROM %s WHERE id = ?', $this->settingTable() ),
            array( $this->installId( $name ) ) );

        return $row ? (array) $row : null;
    }

    /**
     * @param  string $name
     * @return string
     */
    private function installId( $name ) {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', self::INSTALL_SCOPE_ID, 'base', $name );
    }

    /**
     * Upsert an install row of base.<name> holding this serialized value.
     *
     * @param  string $name
     * @param  string $value serialized
     * @param  int    $autoload 1 or 0
     * @return bool
     */
    private function writeInstallRow( $name, $value, $autoload ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?", $this->settingTable() ),
            array( $this->installId( $name ), self::INSTALL_SCOPE_ID, $name, $value, (int) $autoload, time(), $value ) ) !== false;
    }

    /**
     * Upsert the install row of the record, not autoloaded.
     *
     * @param  string $value serialized
     * @return bool
     */
    private function writeRecord( $value ) {

        return $this->writeInstallRow( self::BEFORE, $value, 0 );
    }

    /**
     * Delete the record's install row.
     *
     * @return bool
     */
    private function deleteRecord() {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->settingTable() ),
            array( $this->installId( self::BEFORE ) ) ) !== false;
    }

    /**
     * A row put back exactly as SELECT * read it, into this table or the settings table.
     *
     * @param  array<string, scalar|null> $row
     * @param  string|null                $table
     * @return bool
     */
    private function restoreRow( array $row, $table = null ) {

        $table   = $table ?? $this->settingTable();
        $columns = array_keys( $row );

        foreach ( $columns as $column ) {

            // Names the table gave, but they are interpolated, so only plain ones.
            if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {

                return false;
            }
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( (string) $row['id'] ) ) === false ) {

            return false;
        }

        return $db->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ', ', $columns ),
            implode( ', ', array_fill( 0, count( $columns ), '?' ) ) ), array_values( $row ) ) !== false;
    }
}

?>
