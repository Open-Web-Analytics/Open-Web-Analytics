<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Drop the v1 (1.x) tracking tables, once their history has been migrated.
 *
 *   php cli.php cmd=v1-drop           what would be dropped, and what it holds
 *   php cli.php cmd=v1-drop --drop    drop them
 *
 * OPTIONAL (PLAN.html 2.21). The migration never writes v1's tables, so until
 * they are dropped an installation can still go back to 1.14. After, there is
 * nothing to go back to, and rows older than the migration's cutoff -- never
 * migrated -- are gone. Without --drop this only reports, so that is read
 * before anything is lost.
 *
 * Refused until the migration (Update062) has run: it reads these tables.
 */
class V1DropCli extends \OWA\Core\Controller\Cli {

    /** The schema at which the migration is complete. */
    const MIGRATED_SCHEMA = 62;

    /** The table prefix v1 is read under; a test names a fixture's. */
    public $prefix = null;

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $schema = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

        if ( $schema < self::MIGRATED_SCHEMA ) {

            return $this->refuse( sprintf( 'The v1 migration has not run (schema %d, needs %d). Run'
                . ' cmd=update first: it reads these tables.', $schema, self::MIGRATED_SCHEMA ) );
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $prefix = $this->prefix ?? \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );
        $tables = \OWA\Module\Base\Classes\Migration\V1Tables::present( $db, $prefix );

        if ( ! $tables ) {

            $this->write( 'No v1 tables: nothing to drop.' );

            return;
        }

        $read  = $this->rowsReadBySource();
        $lines = array( 'v1 tables, with what the migration read from each:' );

        foreach ( $tables as $table ) {

            $name = $prefix . $table;
            $rows = (int) ( (array) $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s', $name ) ) )['n'];

            $lines[] = sprintf( '  %-36s %12d rows%s', $name, $rows,
                isset( $read[ $table ] ) ? sprintf( ', %d read by the migration', $read[ $table ] ) : '' );
        }

        if ( ! $this->getParam( 'drop' ) ) {

            $lines[] = '';
            $lines[] = 'Nothing was dropped. Rows the migration did not read -- older than its cutoff,'
                . ' or for sites that no longer exist -- are lost with the tables, and 1.14 can'
                . ' no longer be restored. Run again with --drop to drop them.';

            $this->write( $lines );

            return;
        }

        $this->write( $lines );

        foreach ( $tables as $table ) {

            if ( $db->query( sprintf( 'DROP TABLE IF EXISTS %s', $prefix . $table ) ) === false ) {

                return $this->fail( sprintf( 'Dropping %s%s failed; the tables before it are dropped.',
                    $prefix, $table ) );
            }
        }

        $this->write( sprintf( 'Dropped %d v1 table(s).', count( $tables ) ) );
    }

    /** @return array v1 table => rows the migration read from it, over every site */
    private function rowsReadBySource() {

        $progress = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $db       = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->tableExists( $progress->getTableName() ) ) {

            return array();
        }

        $read = array();

        foreach ( (array) $db->get_results( sprintf(
                'SELECT source, SUM(rows_read) AS n FROM %s GROUP BY source', $progress->getTableName() ) ) as $row ) {

            $row = (array) $row;
            $read[ (string) $row['source'] ] = (int) $row['n'];
        }

        return $read;
    }
}

?>
