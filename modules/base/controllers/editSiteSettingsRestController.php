<?php

require_once(OWA_BASE_MODULE_DIR.'sitesEditSettings.php');

class owa_editSiteSettingRestController extends owa_sitesEditSettingsController {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.editSiteSettingsRest' );
	}
	
	function errorAction() {
            
		http_response_code(422);
		
		$this->setView( 'base.editSiteSettingsRest' );

	}
        
}	

require_once(OWA_DIR.'owa_view.php');

class owa_editSiteSettingsRestView extends owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('site') );
	}
}

?>
