<?php
namespace OWA\Module\Base\Update;

/**
 * One live Property per host: owa_property gains live_host, with a UNIQUE
 * index (Entity\Property::LIVE_HOST_INDEX).
 *
 * live_host is the domain's host (Spam\Hosts::of(): lower case, no scheme,
 * port or path, no leading www.) while the Property is a live web Property,
 * and NULL otherwise. A real column the code keeps (Entity\Property::create()
 * and update()) rather than a generated one: the host is PHP's parse of the
 * domain, which SQL can only approximate, and an entity cannot write a row
 * that has a generated column it declares.
 *
 * REFUSES live duplicates. When two live web Properties have one host, up()
 * changes nothing and fails, naming each; cmd=property id=<id> --archive
 * archives the ones to drop, and runs while this update is pending.
 *
 * Idempotent: the column and the index are added when missing, and live_host
 * is set on every row on each run. down() drops the index and the column,
 * and finds nothing to drop when run again.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update103 extends \OWA\Core\Update {

    public $schema_version = 103;

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( 'base.property' );

        $hosts = [];

        foreach ( (array) $db->get_results( sprintf( 'SELECT * FROM %s ORDER BY id', $table ) ) as $row ) {

            $host = self::liveHostOf( (array) $row );

            if ( $host !== null ) {

                $hosts[ $host ][] = sprintf( '%s (%s)', $row['id'], $row['name'] );
            }
        }

        $twice = array_filter( $hosts, static fn ( $ids ) => count( $ids ) > 1 );

        if ( $twice ) {

            foreach ( $twice as $host => $properties ) {

                $this->e->notice( sprintf( 'Live Properties %s all have the domain %s.', implode( ', ', $properties ), $host ) );
            }

            $this->e->notice( 'Only one live Property may have a domain. Archive the others with cli.php cmd=property id=<property id> --archive, then run the update again.' );

            return false;
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setTableName( $table, '' );

        if ( $this->addColumnIfMissing( $property, 'live_host' ) === false ) {

            $this->e->notice( sprintf( 'Adding %s.live_host failed.', $table ) );

            return false;
        }

        foreach ( (array) $db->get_results( sprintf( 'SELECT * FROM %s', $table ) ) as $row ) {

            $row  = (array) $row;
            $host = self::liveHostOf( $row );

            if ( $db->query( sprintf( 'UPDATE %s SET live_host = ? WHERE id = ?', $table ), [ $host, $row['id'] ] ) === false ) {

                $this->e->notice( sprintf( 'Setting live_host on Property %s failed.', $row['id'] ) );

                return false;
            }
        }

        if ( ! $this->indexNamed( $table, \OWA\Module\Base\Entity\Property::LIVE_HOST_INDEX )
             && $db->query( sprintf( 'ALTER TABLE %s ADD UNIQUE INDEX %s (live_host)',
                    $table, \OWA\Module\Base\Entity\Property::LIVE_HOST_INDEX ) ) === false ) {

            $this->e->notice( sprintf( 'Adding the live_host index to %s failed.', $table ) );

            return false;
        }

        // The cached rows have no live_host.
        \OWA\Core\CoreAPI::cacheSingleton()->flush();

        return true;
    }

    /** The exact inverse: the index and the column go. */
    public function down() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( 'base.property' );

        if ( $this->indexNamed( $table, \OWA\Module\Base\Entity\Property::LIVE_HOST_INDEX )
             && $db->query( sprintf( 'DROP INDEX %s ON %s', \OWA\Module\Base\Entity\Property::LIVE_HOST_INDEX, $table ) ) === false ) {

            $this->e->notice( sprintf( 'Dropping the live_host index from %s failed.', $table ) );

            return false;
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setTableName( $table, '' );

        if ( $this->dropColumnIfPresent( $property, 'live_host' ) === false ) {

            $this->e->notice( sprintf( 'Dropping %s.live_host failed.', $table ) );

            return false;
        }

        \OWA\Core\CoreAPI::cacheSingleton()->flush();

        return true;
    }

    /**
     * What live_host holds for a row, as Entity\Property::liveHost() answers it.
     *
     * @param  array<string, mixed> $row
     * @return string|null
     */
    public static function liveHostOf( array $row ) {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setProperties( array_intersect_key( $row, array_flip( [ 'domain', 'property_type', 'archived_date' ] ) ) );

        return $property->liveHost();
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
}
