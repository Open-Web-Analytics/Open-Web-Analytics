<?php
namespace OWA\Module\Base\Controller;

/**
 * Every reporting cube, with a green, yellow or red status each.
 *
 * READ-ONLY. A build cannot run inside a request, so where something needs
 * doing the screens say which command does it. What each status means is
 * Classes\Cube\Status.
 *
 * Install-wide, beside Modules: the cubes are one per Property, but whether
 * the scheduler is building them is a fact about the installation.
 */
class CubeStatus extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        // The same reach as cmd=cube-rebuild and the Modules screen.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

        $this->set( 'cubes', \OWA\Module\Base\Classes\Cube\Status::all() );

        self::hierarchy( $this );

        $this->setSubview( 'base.cubeStatus', [
            'template' => 'cube_status.php', 'vars' => [ 'cubes' ] ] );
    }

    /**
     * The settings chrome both cube screens sit in, at tier 0: install-wide.
     *
     * @param \OWA\Core\AdminController $controller
     * @return void
     */
    public static function hierarchy( $controller ) {

        $site_id = $controller->resolveCurrentSiteId();

        $controller->settingsChrome( [ 'siteId' => $site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $site_id ) );
    }
}
