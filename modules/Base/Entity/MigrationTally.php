<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What the v1 migration expects to find in raw, per pass, site and day,
 * recorded as each batch is written (FactMigrator::apply()) so reconciliation
 * counts against it instead of rebuilding every row a second time.
 *
 * One row per (source, site_id, yyyymmdd, kind, name):
 *
 *   kind 'event'    name an event type: n rows written, their revenue (minor
 *                   units) and line items, and the latest ts among them
 *   kind 'read'     v1 rows read that day (name '')
 *   kind 'refused'  name a refusal reason: n v1 rows refused for it
 *
 * Added to in the batch's own transaction, so a batch rolled back is not
 * counted and a committed batch is never replayed.
 */
class MigrationTally extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'migration_tally' );

        // Lib::wideStringGuid( source|site_id|yyyymmdd|kind|name ).
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'source', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'kind', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'n', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'revenue', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'items', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'max_ts', OWA_DTD_BIGINT ) );
    }

    /** @return string */
    public static function idFor( $source, $site_id, $yyyymmdd, $kind, $name ) {

        return \OWA\Core\Lib::wideStringGuid( implode( '|', array( $source, $site_id, (int) $yyyymmdd, $kind, $name ) ) );
    }
}

?>
