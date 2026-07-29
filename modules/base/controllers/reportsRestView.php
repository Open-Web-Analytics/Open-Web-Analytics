<?php
namespace OWA\Module\Base\View;




class ReportsRest extends \owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('response') );
	}
}

?>