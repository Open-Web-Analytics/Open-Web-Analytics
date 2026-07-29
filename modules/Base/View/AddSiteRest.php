<?php
namespace OWA\Module\Base\View;




class AddSiteRest extends \owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('site') );
	}
}

?>