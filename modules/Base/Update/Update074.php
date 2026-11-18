<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * remote_host goes from owa_event_raw and every cube.
 *
 * Update051 gave the visitor's network host a column, filled from REMOTE_HOST.
 * It no longer does (it is a no-op), so this finds the column only on an
 * installation that ran 051 before that change, and skips it otherwise.
 * Apache sets that only with HostnameLookups On, which is off by default, and
 * nothing reported it: no dimension read the column.
 *
 * NOT CLI-ONLY. Raw drops the column instantly; each cube is rebuilt in place
 * once (see CubeColumn::dropColumns()), which is online.
 */
class Update074 extends \OWA\Core\Update {

    use CubeColumn;

    public $schema_version = 74;

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        return $this->dropColumns( [ 'remote_host' ] );
    }

    /** The column comes back empty: what it held is not kept anywhere. */
    public function down() {

        return $this->restoreColumn( 'remote_host', OWA_DTD_VARCHAR255 );
    }
}
