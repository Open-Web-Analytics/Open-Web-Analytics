<?php
namespace OWA\Module\Base\View;




class ReportsRest extends \OWA\Core\View\RestApi {
	
	function render() {
		
		$this->setResponseData( $this->get('response') );
	}
}

?>