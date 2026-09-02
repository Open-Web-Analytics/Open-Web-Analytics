<?php
namespace OWA\Module\Base\Controller;

/**
 * The funnels of one Property.
 *
 * Its own screen because a funnel is its own thing. It was a section on the
 * goal event form, which said the opposite: that a funnel belongs to a goal
 * event and cannot exist without one. It can -- "where do people drop out of
 * checkout" is a question about a path, and answering it should not require
 * first declaring something worth counting.
 *
 * Same tier as goal events, and for the same reason: a path through a website
 * is a fact about the website.
 */
class Funnels extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'siteId', $siteId );
        $this->set( 'funnels', self::listFor( $siteId ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.funnels' );
    }

    /**
     * One Property's funnels, with what each counts as and how long it is.
     *
     * @return array
     */
    public static function listFor( $siteId ) {

        $propertyId = \OWA\Module\Base\Classes\GoalManager::propertyFor( $siteId );

        /*
         * Guarded: Db::where() drops an empty value rather than matching
         * nothing, so an unparented Profile would list every funnel on the
         * installation.
         */
        if ( ! $propertyId ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $propertyId );
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $rows = (array) $db->getAllRows();

        foreach ( $rows as $i => $row ) {

            $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );
            $funnel->setProperties( $row );

            $rows[ $i ]['step_count'] = count( $funnel->loadSteps() );

            /*
             * What reaching the end counts as, if anything. A funnel with no
             * goal event is a path analysis, which is a legitimate thing to
             * have -- so this says "none", it is not an error.
             */
            $rows[ $i ]['goal_event_name'] = '';

            if ( ! empty( $row['goal_event_id'] ) ) {

                $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
                $goalEvent->load( $row['goal_event_id'] );

                $rows[ $i ]['goal_event_name'] = (string) $goalEvent->get( 'name' );
            }
        }

        return $rows;
    }
}
