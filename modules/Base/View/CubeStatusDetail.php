<?php

namespace OWA\Module\Base\View;

class CubeStatusDetail extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Reporting Cube' );
        $this->body->set_template( 'cube_status_detail.php' );

        // False when no Property was named or it has no id shape; the template says so.
        $this->body->set( 'cube', $this->get( 'cube' ) ?: array() );
    }
}

?>
