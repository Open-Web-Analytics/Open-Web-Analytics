<?php
namespace OWA\Module\Base\View;




class AddSiteRest extends \OWA\Core\View\RestApi {
	
	function render() {
		
		$this->setResponseData( $this->get('site') );
	}
}

?>