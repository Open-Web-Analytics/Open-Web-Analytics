<?php
namespace OWA\Module\Base\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

class ReportRealtime extends \OWA\Core\View {

    function render() {

        $this->body->setTemplateFile( 'base', 'report_realtime.php' );

        foreach ( array( 'realtime_site_id', 'realtime_timezone' ) as $key ) {

            $this->body->set( $key, $this->get( $key ) );
        }
    }
}

?>
