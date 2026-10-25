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
         * The column and the Property chooser read params['propertyId'] and
         * params['siteId'] to know which Property and Profile are current. Not
         * every screen sets 'params' -- most set only 'siteId' or 'propertyId'
         * -- so fall back to those rather than requiring each controller to
         * remember, which is the kind of thing that silently leaves one screen
         * without its context line.
         */
        $params = (array) $this->get( 'params' );

        foreach ( array( 'siteId', 'propertyId' ) as $key ) {

            if ( empty( $params[ $key ] ) && $this->get( $key ) ) {

                $params[ $key ] = $this->get( $key );
            }
        }

        $this->body->set( 'params', $params );

        // The header marks Settings as the section you are in.
        $this->t->set( 'nav_section', 'settings' );

        // The page has a slide-out menu, so the header draws its button (header.php).
        $this->t->set( 'nav_rail', true );

        // The Property chooser is drawn in the header (header.php), from these.
        $this->t->set( 'params', $params );
        $this->t->set( 'site_hierarchy', $this->get( 'site_hierarchy' ) );
        $this->body->set( 'hierarchy_nav', $this->get( 'hierarchy_nav' ) );

        /*
         * The rail, which is the report screens' rail with Settings current.
         * Its report links are of the Property in context: the one the
         * hierarchy nav's Property pages are of, which every screen here has
         * whether or not it names one in its params.
         */
        $propertyId = self::propertyInContext( (array) $this->get( 'hierarchy_nav' ), $params );

        $this->body->set( 'report_nav', \OWA\Core\ReportController::reportNavigation( $propertyId ) );
        $this->body->set( 'nav_property_id', $propertyId );
        $this->body->set( 'nav_access_site', self::accessSiteFor(
            (array) $this->get( 'site_hierarchy' ), $propertyId, (string) ( $params['siteId'] ?? '' ) ) );

        $this->setJs( 'owa.reporting', 'base/dist/owa.reporting-combined-min.js' );
        $this->setCss( 'base/css/owa.admin.css' );
        $this->setCss( 'base/css/owa.report.css' );

        /*
         * The same chrome the reporting screens load, because these draw the
         * same chrome. The top nav's icons are font-awesome classes, and the
         * combined stylesheet carries owa.css -- neither was loaded here, so
         * every settings screen drew the navigation without its icons and
         * without the base styles the header is built on.
         */
        $this->setCss( 'base/css/font-awesome/css/all.min.css' );
        $this->setCss( 'base/css/owa.reporting-css-combined.css' );
    }

    /**
     * The Property a settings screen is in: the one its params name, or the
     * one the hierarchy nav's Property pages are of.
     *
     * @param array $hierarchyNav
     * @param array $params
     * @return string
     */
    public static function propertyInContext( array $hierarchyNav, array $params ) {

        if ( ! empty( $params['propertyId'] ) ) {

            return (string) $params['propertyId'];
        }

        foreach ( (array) ( $hierarchyNav['Property'] ?? array() ) as $entry ) {

            if ( ! empty( $entry['params']['propertyId'] ) ) {

                return (string) $entry['params']['propertyId'];
            }
        }

        return '';
    }

    /**
     * A Profile of the Property the reader may see, which the rail's links
     * are checked against: site access is held per Profile. The screen's own
     * Profile when it has one.
     *
     * @param array  $siteHierarchy as Controller::getSiteHierarchy() returns it
     * @param string $propertyId
     * @param string $siteId
     * @return string
     */
    public static function accessSiteFor( array $siteHierarchy, $propertyId, $siteId ) {

        if ( $siteId !== '' ) {

            return $siteId;
        }

        foreach ( (array) ( $siteHierarchy['properties'] ?? array() ) as $property ) {

            if ( (string) $property['id'] === (string) $propertyId && ! empty( $property['profiles'] ) ) {

                return (string) $property['profiles'][0]['site_id'];
            }
        }

        return '';
    }
}

?>
