<?php
namespace OWA\Module\Base\Controller;

/**
 * CORS Preflight Request Controller
 * 
 * Responds to an OPTIONS preflight request made by browsers for non-simple HTTP CORS requests. 
 *
 */
class CorsPreflight extends \OWA\Core\Controller {

    /** Nothing to do: the answer is the headers the view sends. */
    public function action() {

    }

    public function success() {
        
        http_response_code(200);

        $this->setView( 'base.corsPreflight' );
    }
}
