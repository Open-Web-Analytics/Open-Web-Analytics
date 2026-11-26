<?php
namespace OWA\Module\Base\Update;

/**
 * Attributed source: the last non-direct touch.
 *
 * Three tables, each its own way:
 *
 *   owa_visitor_acquisition  last_touch_*   the visitor's latest non-direct
 *                                           touch, updated at ingest
 *   owa_event_raw            prior_touch_*  that touch as it stood when a
 *                                           returning visitor's session began
 *   every cube               attributed_*   the cube's reading of the two
 *
 * Raw and the visitor store take their columns instantly, each in one ALTER;
 * neither is a swap target. Each cube is rebuilt in place once for all five
 * (Db::alterColumnsRebuilding()), not once per column.
 *
 * HISTORY IS BACKFILLED from the landings raw already holds -- v1's, migrated
 * by Update062 before these columns existed, and any earlier v2 traffic
 * (Classes\TouchBackfill): each landing gets the visitor's last non-direct
 * landing before it, and each visitor's latest one goes on the store. That
 * updates historical raw rows, so this is CLI-only. Cube rows hold '' until
 * their partition is rebuilt:
 *
 *   php cli.php cmd=cube-rebuild from=<first day> to=<today>
 */
class Update066 extends \OWA\Core\Update {

    use CubeColumn;

    public $schema_version = 66;

    /** @var bool */
    public $is_cli_mode_required = true;

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not (see Update063).
     */
    const RAW = [
        'prior_touch_source'       => 'VARCHAR(255) NULL',
        'prior_touch_medium'       => 'VARCHAR(64) NULL',
        'prior_touch_campaign'     => 'VARCHAR(255) NULL',
        'prior_touch_ad'           => 'VARCHAR(255) NULL',
        'prior_touch_referer_host' => 'VARCHAR(255) NULL',
        'prior_touch_ts'           => 'BIGINT NULL',
    ];

    const VISITOR = [
        'last_touch_source'       => 'VARCHAR(255) NULL',
        'last_touch_medium'       => 'VARCHAR(64) NULL',
        'last_touch_campaign'     => 'VARCHAR(255) NULL',
        'last_touch_ad'           => 'VARCHAR(255) NULL',
        'last_touch_referer_host' => 'VARCHAR(255) NULL',
        'last_touch_ts'           => 'BIGINT NULL',
    ];

    const CUBE = [ 'attributed_source', 'attributed_medium', 'attributed_campaign', 'attributed_channel',
        'attributed_ad' ];

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        foreach ( [ 'base.event_raw' => self::RAW, 'base.visitor_acquisition' => self::VISITOR ] as $name => $columns ) {

            $table   = \OWA\Core\CoreAPI::entityFactory( $name )->getTableName();
            $missing = array_diff_key( $columns, array_flip( $this->present( $table, array_keys( $columns ) ) ) );

            if ( $missing && ! $this->alter( $table, $missing, [] ) ) {

                $this->e->notice( sprintf( 'Adding the touch columns to %s failed', $table ) );

                return false;
            }
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $add = [];

            foreach ( array_diff( self::CUBE, $this->present( $table, self::CUBE ) ) as $column ) {

                $add[ $column ] = $entity->getColumnDefinition( $column );
            }

            // One rebuild per cube for all five, not one per column.
            if ( $add && ! \OWA\Core\CoreAPI::dbSingleton()->alterColumnsRebuilding( $table, $add ) ) {

                foreach ( array_keys( $add ) as $column ) {

                    if ( $this->addCubeColumn( $column ) === false ) {

                        return false;
                    }
                }
            }
        }

        if ( ! $this->clearCubeInstantColumns() ) {

            return false;
        }

        $backfill = new \OWA\Module\Base\Classes\TouchBackfill();

        foreach ( $backfill->sites() as $site_id ) {

            if ( ! $backfill->site( $site_id ) ) {

                $this->e->notice( sprintf( 'Backfilling the last touch for site %s failed', $site_id ) );

                return false;
            }
        }

        return true;
    }

    /** The exact inverse. */
    public function down() {

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $present = $this->present( $table, self::CUBE );

            if ( $present && ! \OWA\Core\CoreAPI::dbSingleton()->alterColumnsRebuilding( $table, [], $present ) ) {

                foreach ( array_reverse( $present ) as $column ) {

                    if ( $this->dropCubeColumn( $column ) === false ) {

                        return false;
                    }
                }
            }
        }

        if ( ! $this->clearCubeInstantColumns() ) {

            return false;
        }

        foreach ( [ 'base.visitor_acquisition' => self::VISITOR, 'base.event_raw' => self::RAW ] as $name => $columns ) {

            $table   = \OWA\Core\CoreAPI::entityFactory( $name )->getTableName();
            $present = $this->present( $table, array_keys( $columns ) );

            if ( $present && ! $this->alter( $table, [], $present ) ) {

                $this->e->notice( sprintf( 'Dropping the touch columns from %s failed', $table ) );

                return false;
            }
        }

        return true;
    }

    /**
     * Which of $columns $table has.
     *
     * @param  string       $table
     * @param  list<string> $columns
     * @return list<string>
     */
    private function present( $table, array $columns ) {

        $have = [];

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf( 'SHOW COLUMNS FROM %s', $table ) ) as $row ) {

            $have[ $row['Field'] ] = true;
        }

        return array_values( array_filter( $columns, function ( $c ) use ( $have ) { return isset( $have[ $c ] ); } ) );
    }

    /**
     * One ALTER for a table that is never a swap target, so instant is fine.
     *
     * @param string $table
     * @param array<string, string> $add  name => definition
     * @param list<string>          $drop names
     * @return bool
     */
    private function alter( $table, array $add, array $drop ) {

        $clauses = [];

        foreach ( $add as $column => $definition ) {

            $clauses[] = sprintf( 'ADD COLUMN %s %s', $column, $definition );
        }

        foreach ( $drop as $column ) {

            $clauses[] = sprintf( 'DROP COLUMN %s', $column );
        }

        return \OWA\Core\CoreAPI::dbSingleton()->query(
            sprintf( 'ALTER TABLE %s %s', $table, implode( ', ', $clauses ) ) ) !== false;
    }
}
