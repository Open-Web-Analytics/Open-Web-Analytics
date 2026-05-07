<?php

require_once(OWA_BASE_MODULE_DIR.'usersDelete.php');

class owa_deleteUserRestController extends owa_usersdeleteController {
	
	function success() {
		
		http_response_code(202);
		
		$this->setView( 'base.deleteUserRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.deleteUserRest' );

	}
}	

require_once(OWA_DIR.'owa_view.php');

class owa_deleteUserRestView extends owa_restApiView {
	
	function render() {
		
		return;
	}
}

?>