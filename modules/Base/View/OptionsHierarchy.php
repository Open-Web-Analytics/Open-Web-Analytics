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
        /*
         * The breadcrumb and the tile both read params['siteId'] to know which
         * Profile is current. Not every screen sets 'params' -- most set only
         * 'siteId' -- so fall back to that rather than requiring each
         * controller to remember, which is the kind of thing that silently
         * leaves one screen without its context line.
         */
        $params = (array) $this->get( 'params' );

        if ( empty( $params['siteId'] ) && $this->get( 'siteId' ) ) {

            $params['siteId'] = $this->get( 'siteId' );
        }

        $this->body->set( 'params', $params );
        $this->body->set( 'hierarchy_nav', $this->get( 'hierarchy_nav' ) );
        /*
 * Not ?: -- tier 0 (install-wide) is a legitimate value that ?: would turn
 * into 3, putting a Property and a Profile above Main Configuration.
 */
        $tier = $this->get( 'hierarchy_tier' );

        $this->body->set( 'hierarchy_tier', $tier === '' || $tier === false || $tier === null ? 3 : (int) $tier );

        $this->setJs( 'owa.reporting', 'base/dist/owa.reporting-combined-min.js' );
        $this->setCss( 'base/css/owa.admin.css' );
        $this->setCss( 'base/css/owa.report.css' );
    }
}

?>
