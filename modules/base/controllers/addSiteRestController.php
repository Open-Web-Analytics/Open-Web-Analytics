<?php
namespace OWA\Module\Base\Controller;


require_once(OWA_BASE_MODULE_DIR.'sitesAdd.php');

class AddSiteRest extends \owa_sitesAddController {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addSiteRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addSiteRest' );

	}
}	
