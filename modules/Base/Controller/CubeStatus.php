<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

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

    function __construct( $params ) {

        // The same reach as cmd=cube-rebuild and the Modules screen.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $this->set( 'cubes', \OWA\Module\Base\Classes\Cube\Status::all() );

        self::hierarchy( $this );

        $this->setSubview( 'base.cubeStatus' );
    }

    /**
     * The settings chrome both cube screens sit in, at tier 0: install-wide.
     *
     * @param \OWA\Core\AdminController $controller
     */
    public static function hierarchy( $controller ) {

        $site_id = $controller->resolveCurrentSiteId();

        $controller->set( 'params', array_merge( (array) $controller->params, array( 'siteId' => $site_id ) ) );
        $controller->set( 'site_hierarchy',
            $controller->getSiteHierarchy( $controller->getSitesAllowedForCurrentUser() ) );
        $controller->set( 'hierarchy_nav', $controller->getHierarchyNav( $site_id ) );
        $controller->set( 'hierarchy_tier', 0 );
        $controller->setView( 'base.optionsHierarchy' );
    }
}

?>
