<?php
namespace OWA\Module\Base\Controller;

/**
 * The realtime screen: a Property's last thirty minutes, refreshed as it runs.
 *
 * Replaces 1.x's per-report Live View. Reports read the cube and
 * are as of the last build; this reads raw through v1/realtime and is
 * immediate, so it has no period and no "Data as of" line, and works on a
 * Property whose reports are not ready yet -- it reads no cube.
 *
 * The page carries the API URL, nonce included, and the widget polls it
 * (OWA.realtime, owa.realtime.js).
 */
class ReportRealtime extends \OWA\Core\ReportController {

    public function action() {

        $this->setTitle( \OWA\Core\CoreAPI::t( 'Realtime' ) );
        $this->hideTimeControls();

        $this->set( 'realtime_property_id', (string) $this->getParam( 'propertyId' ) );
        $this->set( 'realtime_timezone', \OWA\Module\Base\Classes\JobStatus::timezone() );

        $this->setSubview( 'base.reportRealtime', [
            'template' => 'report_realtime.php', 'vars' => [ 'realtime_property_id', 'realtime_timezone' ] ] );
    }
}
