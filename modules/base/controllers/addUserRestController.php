<?php
namespace OWA\Module\Base\Controller;


require_once(OWA_BASE_MODULE_DIR.'usersAdd.php');

class AddUserRest extends \owa_usersAddController {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addUserRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addUserRest' );

	}
}	
