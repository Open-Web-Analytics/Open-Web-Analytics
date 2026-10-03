<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * System Health (PLAN 2.30.5): is the installation's background work
 * happening -- the scheduler, its recurring jobs, the one-off job queue and the
 * tracker-ingest intake. What it says is Classes\SystemHealth, which
 * cmd=schedule-status reads too.
 *
 * Read-only: where something needs doing it names the command. Same capability
 * as Reporting Cubes beside it.
 */
class SystemHealth extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $this->set( 'sections', \OWA\Module\Base\Classes\SystemHealth::sections() );

        CubeStatus::hierarchy( $this );
        $this->setSubview( 'base.systemHealth' );
    }
}

?>
