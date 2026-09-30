<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A site's last thirty minutes, from raw (Classes\Realtime).
 *
 *   GET v1/realtime?siteId=<site>                    every card
 *   GET v1/realtime?siteId=<site>&visitorId=<id>     one visitor's events
 *
 * A ReportController for its access checks -- view_reports on the site named
 * -- and nothing else it offers: it reads no cube, so a site whose reports are
 * not ready yet still has a realtime view.
 */
class RealtimeRest extends \OWA\Core\ReportController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'view_reports' );
    }

    function validate() {

        $this->addValidation( 'siteId', $this->getParam( 'siteId' ), 'required', array( 'stopOnError' => true ) );
    }

    function action() {

        $realtime = new \OWA\Module\Base\Classes\Realtime( (string) $this->getParam( 'siteId' ) );
        $visitor  = trim( (string) $this->getParam( 'visitorId' ) );

        $this->set( 'realtime', $visitor !== ''
            ? array( 'visitor' => $visitor, 'events' => $realtime->visitor( $visitor ) )
            : $realtime->summary() );
    }

    function success() {

        http_response_code( 200 );

        $this->setView( 'base.realtimeRest' );
    }

    function errorAction() {

        http_response_code( 422 );

        $this->setView( 'base.restApi' );
    }
}

?>
