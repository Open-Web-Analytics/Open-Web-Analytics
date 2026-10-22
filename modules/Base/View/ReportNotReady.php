<?php

namespace OWA\Module\Base\View;

/**
 * What a report shows, in place of its widgets, while reporting is not ready
 * for its Property. The reason is Classes\Cube\Status::readinessOfProperty().
 */
class ReportNotReady extends \OWA\Core\View {

    function render() {

        $this->body->set_template( 'report_not_ready.php' );

        $this->body->set( 'readiness', (array) $this->get( 'reporting_readiness' ) );
        $this->body->set( 'can_view_cube_status', (bool) $this->get( 'can_view_cube_status' ) );
    }
}

?>
