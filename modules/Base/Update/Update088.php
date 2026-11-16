<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Profile and Property domains as host[:port]: the scheme and trailing slashes
 * 1.x stored are removed from owa_site.domain and owa_property.domain.
 *
 * 1.x stored a site's domain as the wizard built it, `https://www.example.com`.
 * Every save now stores SiteManager::domainOnly() of what was typed, and this
 * applies the same function to the rows saved before. A row it returns
 * unchanged is not written.
 *
 * THE INVERSE. Before a row changes, its domain as it was is kept in the
 * domains_before_088 setting, by table and row id. down() writes each one back
 * and removes the record. A second run of up() finds nothing to change and
 * keeps the first run's values; down() with no record changes nothing.
 *
 * RAW SQL, so a test can point the update at scratch tables.
 *
 * NOT CLI-ONLY. One row per Profile and per Property.
 */
class Update088 extends \OWA\Core\Update {

    var $schema_version = 88;

    var $is_cli_mode_required = false;

    /** What up() changed: { site|property: { id: domain as it was } }. */
    const BEFORE = 'domains_before_088';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** The entities whose domain column this rewrites, by record key. */
    const ENTITIES = array( 'site' => 'base.site', 'property' => 'base.property' );

    /** @var array<string,string> record key => table, instead of the install's. TESTS ONLY. */
    public $tables = array();

    /** @var string|null the settings table to keep the record in instead of the install's. TESTS ONLY. */
    public $settingTable = null;

    private function table( $key ) {

        return $this->tables[ $key ] ?? \OWA\Core\CoreAPI::entityFactory( self::ENTITIES[ $key ] )->getTableName();
    }

    private function settingTable() {

        return $this->settingTable ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    /**
     * The rows whose domain domainOnly() changes, per record key.
     *
     * @return array<string,array<string,array{0:string,1:string}>> key => id => [ as stored, as it becomes ]
     */
    private function changes() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $changes = array();

        foreach ( array_keys( self::ENTITIES ) as $key ) {

            $rows = (array) $db->get_results( sprintf(
                "SELECT id, domain FROM %s WHERE domain IS NOT NULL AND domain != ''", $this->table( $key ) ) );

            foreach ( $rows as $row ) {

                $row  = (array) $row;
                $from = (string) $row['domain'];
                $to   = \OWA\Module\Base\Classes\SiteManager::domainOnly( $from );

                if ( $to !== $from ) {

                    $changes[ $key ][ (string) $row['id'] ] = array( $from, $to );
                }
            }
        }

        return $changes;
    }

    function up( $force = false ) {

        $changes = $this->changes();

        if ( ! $changes ) {

            return true;
        }

        $record = $this->record();

        foreach ( $changes as $key => $rows ) {

            foreach ( $rows as $id => $pair ) {

                // The first run's value is the one down() restores.
                if ( ! isset( $record[ $key ][ $id ] ) ) {

                    $record[ $key ][ $id ] = $pair[0];
                }
            }
        }

        // Kept before anything changes, so a run that stops part way can still be undone.
        if ( ! $this->writeRecord( serialize( $record ) ) ) {

            $this->e->notice( sprintf( 'Keeping the domains in %s failed.', self::BEFORE ) );

            return false;
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $count = 0;

        foreach ( $changes as $key => $rows ) {

            foreach ( $rows as $id => $pair ) {

                if ( $db->query( sprintf( 'UPDATE %s SET domain = ? WHERE id = ?', $this->table( $key ) ),
                        array( $pair[1], (string) $id ) ) === false ) {

                    $this->e->notice( sprintf( 'Rewriting the domain of %s %s failed.', $key, $id ) );

                    return false;
                }

                $count++;
            }
        }

        $this->e->notice( sprintf( 'Domains: %d %s stored without a scheme.', $count,
            \OWA\Core\Lib::plural( $count, 'row', 'rows' ) ) );

        return true;
    }

    /** The exact inverse, from the record up() kept. */
    function down() {

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $record as $key => $rows ) {

            if ( ! isset( self::ENTITIES[ $key ] ) || ! is_array( $rows ) ) {

                continue;
            }

            foreach ( $rows as $id => $domain ) {

                if ( $db->query( sprintf( 'UPDATE %s SET domain = ? WHERE id = ?', $this->table( $key ) ),
                        array( (string) $domain, (string) $id ) ) === false ) {

                    $this->e->notice( sprintf( 'Restoring the domain of %s %s failed.', $key, $id ) );

                    return false;
                }
            }
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->settingTable() ),
                array( $this->recordId() ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /** What up() kept, or an empty array. */
    private function record() {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT value FROM %s WHERE id = ?', $this->settingTable() ),
            array( $this->recordId() ) );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) ( (array) $row )['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    private function recordId() {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', self::INSTALL_SCOPE_ID, 'base', self::BEFORE );
    }

    /** Upsert the install row of the record. */
    private function writeRecord( $value ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, 0, ?) ON DUPLICATE KEY UPDATE value = ?", $this->settingTable() ),
            array( $this->recordId(), self::INSTALL_SCOPE_ID, self::BEFORE, $value, time(), $value ) ) !== false;
    }
}

?>
