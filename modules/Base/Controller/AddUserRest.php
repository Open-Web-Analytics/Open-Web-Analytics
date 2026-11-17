<?php
namespace OWA\Module\Base\Controller;

class AddUserRest extends \OWA\Module\Base\Controller\UsersAdd {

    public function success() {

        $this->restResponse( 201, 'user' );
    }

    public function errorAction() {

        $this->restResponse( 422, 'user' );
    }
}
