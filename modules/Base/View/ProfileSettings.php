<?php

namespace OWA\Module\Base\View;

class ProfileSettings extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Observation Settings' );
        $this->body->set_template( 'profile_settings.php' );
        $this->body->set( 'site', $this->get( 'site' ) );
        $this->body->set( 'config', $this->get( 'config' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
    }
}

?>
