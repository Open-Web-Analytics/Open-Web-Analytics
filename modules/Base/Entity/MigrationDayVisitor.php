<?php
namespace OWA\Module\Base\Entity;

/**
 * The visitors a v1 migration pass wrote rows for, per site and day: one row
 * per (source, site_id, yyyymmdd, visitor_id), recorded with each batch.
 *
 * A distinct count does not add up across batches, so reconciliation needs the
 * set. Kept beside MigrationTally for the same reason: to count against what was
 * written rather than rebuild every row again.
 */
class MigrationDayVisitor extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'migration_day_visitor' );

        // Lib::wideStringGuid( source|site_id|yyyymmdd|visitor_id ).
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'source', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'visitor_id', OWA_DTD_BIGINT ) );
    }

    /**
     * @param  string     $source
     * @param  string     $site_id
     * @param  int|string $yyyymmdd
     * @param  int|string $visitor_id
     * @return int
     */
    public static function idFor( $source, $site_id, $yyyymmdd, $visitor_id ) {

        return \OWA\Core\Lib::wideStringGuid( implode( '|', [ $source, $site_id, (int) $yyyymmdd, $visitor_id ] ) );
    }
}
