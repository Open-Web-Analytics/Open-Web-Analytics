<?php

namespace OWA\Module\Base\Update;

/**
 * The visitor's network host gets a column.
 *
 * There are three hosts on a tracking event and v2 had columns for one of them:
 *
 *   host         the PAGE's hostname, cut from page_location
 *   HTTP_HOST    THIS SERVER's, the Host header the beacon was posted to
 *   remote_host  the VISITOR's, reverse DNS of their address
 *
 * v1 reported the third one as its `host` dimension -- Entity\Host says so in as
 * many words, "the visitor came from some host", and it kept `full_host` beside
 * it for the unreduced name. v2 gave the name `host` to the page's hostname, so
 * the visitor's network had nowhere left to land and was being read off the
 * request on every event and then dropped.
 *
 * USUALLY EMPTY, and that is the web server's decision: Apache fills
 * REMOTE_HOST only with HostnameLookups On, off by default because it costs a DNS
 * round trip per request. A column of NULLs is the honest record of that -- the
 * alternative is a dimension that silently means "the few installs that enabled
 * a lookup".
 *
 * NOT CLI-ONLY. One column on raw and the cube.
 */
class Update051 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 51;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->addRawColumn( 'remote_host' );
    }

    function down() {

        if ( $this->dropCubeColumn( 'remote_host' ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, 'remote_host' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.remote_host failed', $raw->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
