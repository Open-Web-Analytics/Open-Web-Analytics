<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_migration_progress: how far the v1 migration has got, per source table
 * and site.
 *
 * The migration is forced, blocking and resumable (PLAN.html 2.21), so it has
 * to know where it stopped. It also holds what validation reports -- rows read,
 * written and refused by reason -- and when each source finished, which is
 * what the optional v1 drop refuses without.
 *
 * `last_id` is the v1 row id the migration has passed, as text: v1 ids are
 * signed 64-bit, and some installations hold them in VARCHAR columns.
 */
class MigrationProgress extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'migration_progress' );

        // Lib::wideStringGuid( source|site_id ).
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        // The v1 table read, unprefixed: V1Tables names it.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'source', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'last_id', OWA_DTD_VARCHAR64 ) );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'rows_read', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'rows_written', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'rows_refused', OWA_DTD_BIGINT ) );

        // reason => count, JSON.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'refusals', OWA_DTD_TEXT ) );

        // Unix seconds.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'started_at', OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'completed_at', OWA_DTD_BIGINT ) );
    }

    /** @return string the id of one source table's progress on one site */
    public static function idFor( $source, $site_id ) {

        return \OWA\Core\Lib::wideStringGuid( $source . '|' . $site_id );
    }
}

?>
