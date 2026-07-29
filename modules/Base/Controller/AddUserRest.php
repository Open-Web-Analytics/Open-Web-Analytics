<?php
namespace OWA\Module\Base\Controller;



class AddUserRest extends \OWA\Module\Base\Controller\UsersAdd {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addUserRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addUserRest' );

	}
}	
