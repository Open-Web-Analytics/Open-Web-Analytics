<?php
namespace OWA\Module\Base\Controller;

class AddSiteRest extends \OWA\Module\Base\Controller\SitesAdd {

    function success() {

        $this->restResponse( 201, 'site' );
    }

    function errorAction() {

        $this->restResponse( 422, 'site' );
    }
}
