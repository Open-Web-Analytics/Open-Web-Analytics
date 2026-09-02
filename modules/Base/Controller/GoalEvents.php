<?php
namespace OWA\Module\Base\Controller;

/**
 * The goal events of one Observation Profile.
 *
 * Replaces the goals screen, which listed twenty numbered slots whether or not
 * anyone had filled them in -- because the storage was a fixed-length array and
 * the screen showed the storage. This lists what exists.
 *
 * "Key event" rather than "goal" is v2's name for the same idea, and GA's:
 * conversions were renamed goal events in 2024. Naming it that here means the
 * screen does not have to be relearned when v2 lands.
 */
class GoalEvents extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'siteId', $siteId );
        $this->set( 'goalEvents', self::listFor( $siteId ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 3: a goal event belongs to one Observation Profile. */
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEvents' );
    }

    /**
     * One Profile's goal events, newest slot last.
     *
     * Rows, not slots: a Profile with two goal events has two, and one with none
     * has none rather than twenty blanks.
     *
     * @return array
     */
    public static function listFor( $siteId ) {

        if ( ! $siteId ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'site_id', $siteId );
        // ASC explicitly: orderBy() with no direction is DESC.
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $rows = (array) $db->getAllRows();

        /*
         * How many funnel steps each one has, so the list can offer the funnel
         * report only where there is a funnel to look at.
         *
         * The old report chose a goal from a dropdown of twenty NUMBERS -- all
         * twenty, whether or not a goal existed there or had any steps -- so
         * the one funnel worth opening was indistinguishable from nineteen that
         * were not. Counted here rather than in the template, which should not
         * be running queries.
         */
        foreach ( $rows as $i => $row ) {

            $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goalEvent->setProperties( $row );

            $rows[ $i ]['step_count'] = count( $goalEvent->loadSteps() );
        }

        return $rows;
    }
}
