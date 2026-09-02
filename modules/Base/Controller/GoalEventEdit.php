<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or edit one goal event.
 *
 * Replaces the goal entry screen, which edited a numbered SLOT -- so creating a
 * goal meant picking an unused number out of twenty, and there was no
 * twenty-first. A goal event is a row: it is created, not claimed.
 */
class GoalEventEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( $this->getParam( 'goalEventId' ) ) {

            $goalEvent->load( $this->getParam( 'goalEventId' ) );

        } elseif ( $this->getParam( 'goal_number' ) ) {

            /*
             * By SLOT, for the funnel report's "edit this funnel" links.
             *
             * That report addresses a goal by number -- it has no id to pass --
             * so this resolves one, rather than those links being repointed at
             * a screen that would silently open a blank form and create a
             * second goal event on save.
             */
            $goalEvent->load( \OWA\Module\Base\Classes\GoalManager::goalEventIdFor(
                $siteId, $this->getParam( 'goal_number' ) ) );
        }

        $this->set( 'goalEvent', $goalEvent->_getProperties() );

        $conditions = array();

        foreach ( $goalEvent->get( 'id' ) ? $goalEvent->loadConditions() : array() as $condition ) {

            $conditions[] = $condition->_getProperties();
        }

        $this->set( 'conditions', $conditions );
        /* Whatever it resolved to, so the form saves the row it opened. */
        $this->set( 'goalEventId', $goalEvent->get( 'id' ) );
        $this->set( 'siteId', $siteId );
        $this->set( 'conditionProperties', self::conditionProperties() );

        /*
         * Group labels still live in settings -- they are labels, not records,
         * and there are five of them. Only the goal events themselves moved.
         */
        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $this->set( 'goalGroups', $gm->getAllGoalGroupLabels() );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventEdit' );
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
        unset( $names[ \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI ] );

        ksort( $names );

        $out = array( array(
            'name'  => \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI,
            'label' => 'Page URL',
        ) );

        foreach ( $names as $name ) {

            $out[] = array( 'name' => $name, 'label' => ucwords( str_replace( '_', ' ', $name ) ) );
        }

        return $out;
    }
}
