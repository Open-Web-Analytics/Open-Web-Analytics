<?php
namespace OWA\Module\Base\Controller;

class AddSiteRest extends \OWA\Module\Base\Controller\SitesAdd {

    public function success() {

        $this->restResponse( 201, 'site' );
    }

    public function errorAction() {

        $this->restResponse( 422, 'site' );
    }
}
