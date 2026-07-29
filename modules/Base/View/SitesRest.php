<?php
namespace OWA\Module\Base\View;


/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */



/**
 * Sites Roster View
 * 
 */
class SitesRest extends \OWA\Core\View\RestApi {
        
    function render() {
        
        $this->setResponseData( $this->get('tracked_sites') );
    }
}

?>