<?php
namespace OWA\Module\Base\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

class RealtimeRest extends \OWA\Core\View\RestApi {

    function render() {

        $this->setResponseData( (array) $this->get( 'realtime' ) );
    }
}

?>
