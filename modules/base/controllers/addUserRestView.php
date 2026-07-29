<?php
namespace OWA\Module\Base\View;




class AddUserRest extends \owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('user') );
	}
}

?>