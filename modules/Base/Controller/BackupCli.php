<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\Backup;

/**
 * Dump OWA's tables to a gzipped SQL file (Classes\Backup).
 *
 *   php cli.php cmd=backup scope=application    configuration, users, Properties, goals, settings
 *   php cli.php cmd=backup scope=data           the tables tracking writes
 *   php cli.php cmd=backup scope=all            both, and every table no entity owns
 *   ... dir=/var/backups/owa                    in place of OWA_BACKUP_DIR
 *
 * The cubes are never dumped: cmd=cube-rebuild makes them from owa_event_raw.
 * Nor are the 1.x tracking tables, which cmd=v1-drop removes. The summary
 * lists both, and the tables no entity owns. The directory must be outside
 * OWA's directory (Backup::dirProblem()). OWA never applies the file; its
 * header says how to restore it.
 *
 * v2 only: refused until the 1.x migration has run, since before it the
 * tables are 1.x's. Runs while later updates are pending, since that is when
 * a backup is taken: after new code is in place and before cmd=core-update-schema.
 * A table a pending update is about to rename is owned by no entity until it
 * runs, and scope=all includes it.
 */
class BackupCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Writes OWA\'s tables to a gzipped SQL file, mode 0600, for restoring outside OWA. The cubes are never included (cmd=cube-rebuild rebuilds them), nor are the 1.x tracking tables (cmd=v1-drop removes them); the summary lists both, and the tables no entity owns. Refused before the 1.x migration has run.',
            'arguments'   => [
                'scope' => [
                    'type'        => 'select',
                    'options'     => array_keys( Backup::SCOPES ),
                    'required'    => true,
                    'description' => 'application: configuration, users, Properties, goals, settings, notices, jobs. data: the tables tracking writes. Each module\'s entities declare which a table is in. all: both, and every other table under OWA\'s prefix, such as one a pending update is about to rename.',
                ],
                'dir' => [
                    'type'        => 'string',
                    'value'       => '<path>',
                    'description' => 'The directory to write to, outside OWA\'s directory. Defaults to OWA_BACKUP_DIR.',
                ],
            ],
        ];
    }

    /** @var string|null The table prefix dumped; a test names its own. */
    public $prefix = null;

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** A backup is taken before pending updates are applied. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $scope = (string) $this->getParam( 'scope' );

        if ( ! isset( Backup::SCOPES[ $scope ] ) ) {

            return $this->usageError( sprintf( 'scope= is one of %s.', implode( ', ', array_keys( Backup::SCOPES ) ) ) );
        }

        $schema = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

        if ( $schema < V1DropCli::MIGRATED_SCHEMA ) {

            return $this->refuse( sprintf( 'This installation\'s tables are still 1.x (schema %d; the 1.x migration completes at %d).'
                . ' Back it up with mysqldump.', $schema, V1DropCli::MIGRATED_SCHEMA ) );
        }

        $dir = (string) ( $this->getParam( 'dir' ) ?: \OWA\Core\CoreAPI::getSetting( 'base', 'backup_dir' ) );

        $problem = Backup::dirProblem( $dir, Backup::owaDirs() );

        if ( $problem !== null ) {

            return $this->fail( $problem );
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $backup = new Backup( $db, $this->prefix ?? \OWA\Core\Entity::TABLE_PREFIX );
        $groups = $backup->tables();
        $tables = [];

        foreach ( Backup::SCOPES[ $scope ] as $group ) {

            $tables = array_merge( $tables, $groups[ $group ] );
        }

        if ( ! $tables ) {

            return $this->refuse( sprintf( 'No %s tables to back up.', $scope ) );
        }

        $bytes = 0;

        foreach ( $tables as $table ) {

            $bytes += (int) ( $db->tableSize( $table )['bytes'] ?? 0 );
        }

        $this->write( sprintf( 'Backing up %d %s table%s (%s in the database) to %s.', count( $tables ), $scope,
            \OWA\Core\Lib::plural( count( $tables ), '', 's' ), self::readableSize( $bytes ), realpath( $dir ) ) );

        $backup->progress = function ( $line ) {

            $this->write( '  ' . $line );
        };

        try {

            $done = $backup->write( $scope, $dir, $this->about() );

        } catch ( \RuntimeException $e ) {

            return $this->fail( $e->getMessage() . ' No backup was written.' );
        }

        $this->write( sprintf( 'Wrote %s: %d tables, %s rows, %s.', $done['file'], count( $done['tables'] ),
            number_format( array_sum( $done['tables'] ) ), self::readableSize( (int) filesize( $done['file'] ) ) ) );

        $this->write( $this->summary( $scope, $groups[ Backup::OTHER ], $backup->skipped() ) );
    }

    /**
     * The tables the file does not hold, and those no entity owns.
     *
     * @param  string                                      $scope
     * @param  list<string>                                $other   the tables no entity owns
     * @param  array{cube: list<string>, v1: list<string>} $skipped Backup::skipped()
     * @return list<string>
     */
    private function summary( $scope, array $other, array $skipped ) {

        $lines = [];

        if ( $skipped[ Backup::CUBE ] ) {

            $lines[] = sprintf( \OWA\Core\CoreAPI::t( 'Not included, the cubes, which cmd=cube-rebuild rebuilds from events: %s' ),
                implode( ', ', $skipped[ Backup::CUBE ] ) );
        }

        if ( $skipped[ Backup::V1 ] ) {

            $lines[] = sprintf( \OWA\Core\CoreAPI::t( 'Not included, the 1.x tracking tables, which cmd=v1-drop removes: %s' ),
                implode( ', ', $skipped[ Backup::V1 ] ) );
        }

        if ( $other ) {

            $lines[] = in_array( Backup::OTHER, Backup::SCOPES[ $scope ], true )
                ? sprintf( \OWA\Core\CoreAPI::t( 'Included, owned by no entity: %s' ), implode( ', ', $other ) )
                : sprintf( \OWA\Core\CoreAPI::t( 'Not included, owned by no entity (scope=all includes them): %s' ), implode( ', ', $other ) );
        }

        return $lines;
    }

    /**
     * The header's facts beside the version: each active module's stored schema.
     *
     * @return array<string, string>
     */
    private function about() {

        $schemas = [];

        foreach ( (array) \OWA\Core\CoreAPI::getActiveModules() as $name ) {

            $schemas[] = sprintf( '%s %d', $name, (int) \OWA\Core\CoreAPI::getSetting( $name, 'schema_version' ) );
        }

        return [ 'Schema' => implode( ', ', $schemas ) ];
    }
}
