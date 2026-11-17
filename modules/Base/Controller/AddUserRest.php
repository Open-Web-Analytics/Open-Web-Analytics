<?php
namespace OWA\Module\Base\Controller;

class AddUserRest extends \OWA\Module\Base\Controller\UsersAdd {

    function success() {

        $this->restResponse( 201, 'user' );
    }

    function errorAction() {

        $this->restResponse( 422, 'user' );
    }
}
