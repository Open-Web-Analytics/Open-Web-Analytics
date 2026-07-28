<?php
namespace OWA\Module\Base\View;


/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */


require_once(OWA_DIR.'owa_view.php');

/**
 * View
 * 
 */
class SiteAddAllowedUserRest extends \owa_restApiView {
        
    function render() {
        
        $this->setResponseData( $this->get('response') );
    }
}

?>