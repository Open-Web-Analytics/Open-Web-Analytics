<?php

namespace OWA\Module\Base\View;

/**
 * Wrapper for the hierarchy's edit screens.
 *
 * Differs from base.options in one way that matters: the left column is the
 * site control, not the admin settings panels. These screens are reached from
 * that control and describe a tier of the hierarchy; the settings menu belongs
 * to install-wide configuration and is reached from the top nav.
 */
class OptionsHierarchy extends \OWA\Core\View {

    function render( $data ) {

        $this->t->set( 'page_title', 'OWA Options' );
        $this->body->set_template( 'options_hierarchy.php' );
        $this->body->set( 'site_hierarchy', $this->get( 'site_hierarchy' ) );
        $this->body->set( 'params', $this->get( 'params' ) );
        $this->body->set( 'hierarchy_nav', $this->get( 'hierarchy_nav' ) );

        $this->setJs( 'owa.reporting', 'base/dist/owa.reporting-combined-min.js' );
        $this->setCss( 'base/css/owa.admin.css' );
        $this->setCss( 'base/css/owa.report.css' );
    }
}

?>
