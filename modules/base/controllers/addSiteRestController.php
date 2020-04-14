<?php

require_once(OWA_BASE_MODULE_DIR.'sitesAdd.php');

class owa_addSiteRestController extends owa_sitesAddController {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addSiteRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addSiteRest' );

	}
}	

require_once(OWA_DIR.'owa_view.php');

class owa_addSiteRestView extends owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('site') );
	}
}

?>