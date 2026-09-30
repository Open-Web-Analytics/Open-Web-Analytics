<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The realtime screen: a site's last thirty minutes, refreshed as it runs.
 *
 * Replaces 1.x's per-report Live View (PLAN 1.6). Reports read the cube and
 * are as of the last build; this reads raw through v1/realtime and is
 * immediate, so it has no period and no "Data as of" line, and works on a
 * site whose reports are not ready yet -- it reads no cube.
 *
 * The page carries the API URL, nonce included, and the widget polls it
 * (OWA.realtime, owa.realtime.js).
 */
class ReportRealtime extends \OWA\Core\ReportController {

    function action() {

        $this->setTitle( 'Realtime' );
        $this->hideTimeControls();

        $this->set( 'realtime_site_id', (string) $this->getParam( 'siteId' ) );
        $this->set( 'realtime_timezone', \OWA\Module\Base\Classes\JobStatus::timezone() );

        $this->setSubview( 'base.reportRealtime' );
    }
}

?>
