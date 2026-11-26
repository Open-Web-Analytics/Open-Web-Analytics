<?php
namespace OWA\Module\Base\Update;

/**
 * site_transaction leaves every cube.
 *
 * A purchase's transaction id is looked up on raw only, by ingest
 * (Classes\PurchaseDeduplication, TrackingEventHelpers); nothing reads it by
 * index on a cube, and every build's staging insert maintained it. Raw keeps
 * it (Update057). Entity\Event no longer declares it, so a cube made after
 * this has none, and a build's staging table matches the cube it swaps into.
 *
 * Idempotent: a cube without the index is left alone. down() puts the index
 * back on every cube, as Update057 made it.
 *
 * NOT CLI-ONLY: dropping and adding a secondary index are online operations.
 */
class Update096 extends \OWA\Core\Update {

    public $schema_version = 96;

    const INDEX = 'site_transaction';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            if ( ! $this->hasIndex( $table ) ) {

                continue;
            }

            if ( $db->query( sprintf( 'DROP INDEX %s ON %s', self::INDEX, $table ) ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s on %s failed', self::INDEX, $table ) );

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: the index on every cube, as Update057 made it. */
    public function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            if ( $this->hasIndex( $table ) ) {

                continue;
            }

            if ( $db->query( sprintf(
                    'ALTER TABLE %s ADD INDEX %s (site_id, transaction_id)',
                    $table, self::INDEX ) ) === false ) {

                $this->e->notice( sprintf( 'Indexing %s failed', $table ) );

                return false;
            }
        }

        return true;
    }

    /**
     * @param  string $table
     * @return bool
     */
    private function hasIndex( $table ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = '%s' LIMIT 1",
            $table, self::INDEX ) );
    }
}
