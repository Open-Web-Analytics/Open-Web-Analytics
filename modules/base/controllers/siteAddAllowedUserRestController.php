<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */

require_once(OWA_DIR.'owa_adminController.php');

/**
 * Add Site allowed User REST Controller
 *
 * Adds a new allowed user to a site.
 * 
 */
class owa_siteAddAllowedUserRestController extends owa_adminController {

	function validate() {
		
		$this->addValidation('siteId', $this->getParam('siteId'), 'required', array('stopOnError'	=> true));
	    $this->addValidation('user_id', $this->getParam('user_id'), 'required', array('stopOnError'	=> true));
	}
	
    function action() {

        $site_id = $this->getParam( 'siteId' );
        $s = owa_coreAPI::entityFactory( 'base.site' );
        $s->load( $site_id, 'site_id' );
        
        $user_id = $this->getParam( 'user_id' );
        $u = owa_coreAPI::entityFactory( 'base.user' );
        $u->load( $user_id, 'user_id' );
        
        $relation = owa_coreAPI::entityFactory( 'base.site_user' );
        $relation->set( 'user_id', $u->get( 'id' ) );
        $relation->set( 'site_id', $s->get( 'id' ) );
        $relation->save();
        
        $response = [
	        
	        'site'			=> $s->getProperties(),
	        'allowed_user'	=> $u->getProperties( ['password', 'temp_passkey'] )
        ];
        
        $this->set('response', $response);
        
    }
    
    function success() {
	    
	    http_response_code(201);
	    
	    $this->setView( 'base.siteAddAllowedUserRest' );
    }
    
    function errorAction() {
	    
	    http_response_code(422);
	    
	    $this->setView( 'base.siteAddAllowedUserRest' );
    }

}


require_once(OWA_DIR.'owa_view.php');

/**
 * View
 * 
 */
class owa_siteAddAllowedUserRestView extends owa_restApiView {
        
    function render() {
        
        $this->setResponseData( $this->get('response') );
    }
}

?>