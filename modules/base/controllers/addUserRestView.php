<?php
namespace OWA\Module\Base\View;



require_once(OWA_DIR.'owa_view.php');

class AddUserRest extends \owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('user') );
	}
}

?>