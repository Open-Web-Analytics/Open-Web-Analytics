<?php
namespace OWA\Module\Base\Update;

/**
 * Index owa_site on site_id, and drop owa_setting's index on autoload.
 *
 * SITE_ID. Every beacon reads its Profile by site_id, which had no index, so
 * each of those reads scanned owa_site. UNIQUE, as a fresh install declares it
 * (Entity\Site::SITE_ID_INDEX): a Profile's id is derived from its site_id.
 * Where the rows already hold one site_id twice, the index is a plain one and
 * a notice names the site_id, rather than the upgrade failing.
 *
 * AUTOLOAD. No query filters on the column (Entity\Setting), so the index was
 * written on every settings save and read by nothing. An install upgraded
 * through Update043 has it as idx_autoload, a fresh one as autoload; every
 * index on that column alone is dropped. Their names are kept in the
 * autoload_indexes_before_093 setting first, and down() adds each back under
 * its name and removes the record. A run that finds none changes nothing.
 *
 * NOT CLI-ONLY: two small tables.
 */
class Update093 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 93;

    /** What up() dropped: { indexes: list of index names }. */
    const BEFORE = 'autoload_indexes_before_093';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $site = $this->tableFor( 'base.site' );

        if ( ! $this->indexNamed( $site, \OWA\Module\Base\Entity\Site::SITE_ID_INDEX ) ) {

            $twice = $db->get_row( sprintf(
                'SELECT site_id FROM %s WHERE site_id IS NOT NULL GROUP BY site_id HAVING COUNT(*) > 1 LIMIT 1', $site ) );

            if ( $twice ) {

                $this->e->notice( sprintf( 'site_id %s names more than one row of %s, so its index is not unique.',
                    \OWA\Core\CoreAPI::siteIdForLog( $twice['site_id'] ), $site ) );
            }

            if ( $db->query( sprintf( 'ALTER TABLE %s ADD %s %s (site_id)',
                    $site, $twice ? 'INDEX' : 'UNIQUE INDEX', \OWA\Module\Base\Entity\Site::SITE_ID_INDEX ) ) === false ) {

                $this->e->notice( sprintf( 'Adding the site_id index to %s failed.', $site ) );

                return false;
            }
        }

        $setting = $this->settingTable();
        $names   = $this->indexesOn( $setting, 'autoload' );

        if ( ! $names ) {

            return true;
        }

        // Kept before anything is dropped, so a run that stops part way can still be undone.
        $record = $this->record();
        $kept   = isset( $record['indexes'] ) && is_array( $record['indexes'] ) ? $record['indexes'] : [];

        $record['indexes'] = array_values( array_unique( array_merge( $kept, $names ) ) );

        if ( ! $this->writeRecord( serialize( $record ) ) ) {

            $this->e->notice( sprintf( 'Keeping the autoload index names in %s failed.', self::BEFORE ) );

            return false;
        }

        foreach ( $names as $name ) {

            if ( $db->query( sprintf( 'DROP INDEX %s ON %s', $name, $setting ) ) === false ) {

                $this->e->notice( sprintf( 'Dropping index %s from %s failed.', $name, $setting ) );

                return false;
            }
        }

        return true;
    }

    public function down() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $setting = $this->settingTable();
        $record  = $this->record();

        foreach ( (array) ( $record['indexes'] ?? [] ) as $name ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ) {

                continue;
            }

            if ( ! $this->indexNamed( $setting, (string) $name )
                 && $db->query( sprintf( 'ALTER TABLE %s ADD INDEX %s (autoload)', $setting, $name ) ) === false ) {

                $this->e->notice( sprintf( 'Adding index %s to %s failed.', $name, $setting ) );

                return false;
            }
        }

        if ( $record && ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        $site = $this->tableFor( 'base.site' );

        if ( $this->indexNamed( $site, \OWA\Module\Base\Entity\Site::SITE_ID_INDEX )
             && $db->query( sprintf( 'DROP INDEX %s ON %s', \OWA\Module\Base\Entity\Site::SITE_ID_INDEX, $site ) ) === false ) {

            $this->e->notice( sprintf( 'Dropping the site_id index from %s failed.', $site ) );

            return false;
        }

        return true;
    }

    /**
     * @param  string $table
     * @param  string $name
     * @return bool
     */
    private function indexNamed( $table, $name ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
          . ' AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [ $table, $name ] );
    }

    /**
     * The names of the indexes on this column and no other.
     *
     * @param  string $table
     * @param  string $column
     * @return list<string>
     */
    private function indexesOn( $table, $column ) {

        $names = [];

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
              . ' AND TABLE_NAME = ? GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME) = ?',
                [ $table, $column ] ) as $row ) {

            $names[] = (string) $row['name'];
        }

        return $names;
    }
}
