<?php

namespace OWA\Module\Base\View;

class CubeStatus extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Reporting Cubes' );
        $this->body->set_template( 'cube_status.php' );
        $this->body->set( 'cubes', (array) $this->get( 'cubes' ) );
    }
}

?>
