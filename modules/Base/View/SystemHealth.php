<?php
namespace OWA\Module\Base\View;

class SystemHealth extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'System Health' );
        $this->body->set_template( 'system_health.php' );
        $this->body->set( 'sections', (array) $this->get( 'sections' ) );
    }
}

?>
