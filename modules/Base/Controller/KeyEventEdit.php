<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or edit one key event.
 *
 * Replaces the goal entry screen, which edited a numbered SLOT -- so creating a
 * goal meant picking an unused number out of twenty, and there was no
 * twenty-first. A key event is a row: it is created, not claimed.
 */
class KeyEventEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        if ( $this->getParam( 'keyEventId' ) ) {

            $keyEvent->load( $this->getParam( 'keyEventId' ) );
        }

        $this->set( 'keyEvent', $keyEvent->_getProperties() );
        $this->set( 'keyEventId', $this->getParam( 'keyEventId' ) );
        $this->set( 'siteId', $siteId );
        $this->set( 'conditionProperties', self::conditionProperties() );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.keyEventEdit' );
    }

    /**
     * What a condition can be written against.
     *
     * Drawn from the tracking property registry rather than hardcoded, so the
     * list is the properties an event actually carries -- and so it grows with
     * them instead of drifting. Labelled the way the reporting constraint
     * builder labels dimensions, because this is the same act.
     *
     * @return array  list of { name, label }
     */
    public static function conditionProperties() {

        $helpers = \OWA\Core\CoreAPI::getInstance( 'owa_trackingEventHelpers',
            OWA_BASE_CLASS_DIR . 'trackingEventHelpers.php' );

        $names = array();

        foreach ( array( 'clientProperties', 'serverProperties' ) as $group ) {

            if ( ! method_exists( $helpers, $group ) ) {

                continue;
            }

            foreach ( array_keys( (array) $helpers->$group() ) as $name ) {

                $names[ $name ] = $name;
            }
        }

        /*
         * page_uri first and always present. It is what every migrated goal
         * tests, so it must be selectable even if the registry is unavailable
         * -- otherwise editing a migrated goal would silently offer no property
         * that matches the one it has.
         */
        unset( $names[ \OWA\Module\Base\Entity\KeyEvent::PROPERTY_PAGE_URI ] );

        ksort( $names );

        $out = array( array(
            'name'  => \OWA\Module\Base\Entity\KeyEvent::PROPERTY_PAGE_URI,
            'label' => 'Page URL',
        ) );

        foreach ( $names as $name ) {

            $out[] = array( 'name' => $name, 'label' => ucwords( str_replace( '_', ' ', $name ) ) );
        }

        return $out;
    }
}
