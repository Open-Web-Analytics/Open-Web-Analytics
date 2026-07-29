<?php
namespace OWA\Module\Base\Controller;



class DeleteUserRest extends \owa_usersdeleteController {
	
	function success() {
		
		http_response_code(202);
		
		$this->setView( 'base.deleteUserRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.deleteUserRest' );

	}
}	
