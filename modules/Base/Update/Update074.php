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
 * nothing reported it: no dimension read the column. On app it was empty in all
 * 1,131,431 rows. The reverse-DNS lookup v1 did itself went in 62a1d702, and the
 * resolve_hosts setting that switched it goes with this release.
 *
 * NOT CLI-ONLY. Raw drops the column instantly; each cube is rebuilt in place
 * once (see CubeColumn::dropColumns()), which is online.
 */
class Update074 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 74;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->dropColumns( array( 'remote_host' ) );
    }

    /** The column comes back empty: what it held is not kept anywhere. */
    function down() {

        return $this->restoreColumn( 'remote_host', OWA_DTD_VARCHAR255 );
    }
}

?>
