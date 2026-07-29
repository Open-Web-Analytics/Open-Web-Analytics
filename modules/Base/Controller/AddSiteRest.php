<?php
namespace OWA\Module\Base\Controller;



class AddSiteRest extends \OWA\Module\Base\Controller\SitesAdd {
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.addSiteRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.addSiteRest' );

	}
}	
