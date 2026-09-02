<?php

namespace OWA\Module\Base\View;

class OrganizationProfile extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Organization Details' );
        $this->body->set_template( 'organization_profile.php' );
        $this->body->set( 'organization', $this->get( 'organization' ) );
    }
}

?>
