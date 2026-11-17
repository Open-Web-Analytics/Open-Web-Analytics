<?php
namespace OWA\Module\Base\Controller;

class DeleteUserRest extends \OWA\Module\Base\Controller\UsersDelete {

    function success() {

        $this->restResponse( 202 );
    }

    function errorAction() {

        $this->restResponse( 422 );
    }
}
