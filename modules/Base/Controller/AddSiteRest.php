<?php
namespace OWA\Module\Base\Controller;

class AddSiteRest extends \OWA\Module\Base\Controller\SitesAdd {

    /** 201 with the new Profile; 422 when createSite() made none. */
    public function success() {

        if ( empty( $this->data['site']['site_id'] ) ) {

            $this->set( 'error_msg', $this->getMsg( 3203 ) );
            $this->restResponse( 422, 'site' );

            return;
        }

        $this->restResponse( 201, 'site' );
    }

    public function errorAction() {

        $this->restResponse( 422, 'site' );
    }
}
