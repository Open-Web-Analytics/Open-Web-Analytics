<?php
namespace OWA\Module\Base\View;




class AddUserRest extends \OWA\Core\View\RestApi {
	
	function render() {
		
		$this->setResponseData( $this->get('user') );
	}
}

?>