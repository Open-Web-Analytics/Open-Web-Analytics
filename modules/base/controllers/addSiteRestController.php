<?php

require_once(OWA_BASE_MODULE_DIR.'sitesAdd.php');

class owa_addSiteRestController extends owa_sitesAddController {
	
	function init() {
		
		$this->setMode( 'rest_api' );	
	}
	
	function success() {
		
		$this->setView( 'base.addSiteRest' );
	}
	
	function errorAction() {
		
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