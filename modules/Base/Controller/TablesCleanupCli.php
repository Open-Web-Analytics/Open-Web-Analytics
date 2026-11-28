<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\TableInventory;

/**
 * Drop the old tables: those under OWA's prefix that nothing in the running
 * code owns any more (Classes\TableInventory).
 *
 *   php cli.php cmd=tables-cleanup          what would be dropped, and its size
 *   php cli.php cmd=tables-cleanup --drop   drop them
 *
 * Such as a 1.x table no v2 update dropped (owa_exit), the tables of a module
 * whose directory is gone, or the cube and build tables of a Property whose
 * row is gone. Without --drop this only reports.
 *
 * NOT the cubes of Properties whose rows exist, archived or not, and NOT the
 * 1.x tracking tables: cmd=v1-drop removes those, after the migration that
 * reads them, and records that it did so Update062's down() stops offering a
 * revert.
 *
 * Refused while updates are pending (the default for a CLI command): a table
 * a pending update is about to rename has no entity until it runs.
 */
class TablesCleanupCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Reports the tables under OWA\'s prefix that this version does not use, such as a retired 1.x table, a removed module\'s, or the cube of a Property that no longer exists; with --drop, drops them. Leaves the cubes of Properties that exist, archived or not, and the 1.x tracking tables to cmd=v1-drop. Refused while updates are pending.',
            'arguments'   => [
                '--drop' => 'Drop the tables.',
            ],
        ];
    }

    /** @var string|null The table prefix read; a test names its own. */
    public $prefix = null;

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $tables = ( new TableInventory( $db, $this->prefix ?? \OWA\Core\Entity::TABLE_PREFIX ) )->classify();
        $old    = $tables[ TableInventory::OLD ];
        $v1     = count( $tables[ TableInventory::V1 ] );
        $lines  = [];

        if ( ! $old ) {

            $lines[] = 'No old tables: every table under the prefix is one this version uses.';

        } else {

            $lines[] = 'Tables this version does not use:';

            foreach ( $old as $table ) {

                $size    = $db->tableSize( $table );
                $lines[] = sprintf( '  %-40s about %12s rows  %s', $table, number_format( (int) ( $size['rows'] ?? 0 ) ),
                    self::readableSize( (int) ( $size['bytes'] ?? 0 ) ) );
            }
        }

        if ( $v1 ) {

            $lines[] = sprintf( '%d 1.x tracking table%s left to cmd=v1-drop.', $v1, \OWA\Core\Lib::plural( $v1, '', 's' ) );
        }

        if ( ! $old || ! $this->getParam( 'drop' ) ) {

            if ( $old ) {

                $lines[] = \OWA\Core\CoreAPI::t( 'Nothing was dropped. What these tables hold is lost with them. cmd=backup scope=all'
                    . ' includes all of them but the cubes: back up any you want first. Run again with --drop to drop them.' );
            }

            $this->write( $lines );

            return;
        }

        $this->write( $lines );

        foreach ( $old as $i => $table ) {

            if ( $db->query( sprintf( 'DROP TABLE IF EXISTS `%s`', $table ) ) === false ) {

                return $this->fail( sprintf( 'Dropping %s failed; %d table%s before it %s dropped.', $table, $i,
                    \OWA\Core\Lib::plural( $i, '', 's' ), $i === 1 ? 'was' : 'were' ) );
            }
        }

        $this->write( sprintf( 'Dropped %d table%s.', count( $old ), \OWA\Core\Lib::plural( count( $old ), '', 's' ) ) );
    }
}
