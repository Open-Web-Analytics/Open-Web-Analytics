<?php

namespace OWA\Module\Base\View;

class Properties extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Properties' );
        $this->body->set_template( 'properties.php' );
        $this->body->set( 'properties', $this->get( 'properties' ) );
        $this->body->set( 'unassigned_profiles', $this->get( 'unassigned_profiles' ) );
    }
}

?>
