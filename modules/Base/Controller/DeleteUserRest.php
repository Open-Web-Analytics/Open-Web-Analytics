<?php
namespace OWA\Module\Base\Controller;

class DeleteUserRest extends \OWA\Module\Base\Controller\UsersDelete {

    public function success() {

        $this->restResponse( 202 );
    }

    public function errorAction() {

        $this->restResponse( 422 );
    }
}
