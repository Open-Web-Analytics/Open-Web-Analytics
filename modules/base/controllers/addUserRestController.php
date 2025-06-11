<?php

require_once(OWA_BASE_MODULE_DIR.'usersAdd.php');

class owa_addUserRestController extends owa_usersAddController {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addUserRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addUserRest' );

	}
}	

require_once(OWA_DIR.'owa_view.php');

class owa_addUserRestView extends owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('user') );
	}
}

?>