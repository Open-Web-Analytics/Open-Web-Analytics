<?php
namespace OWA\Module\Base\Controller;

/**
 * The goal event definitions of one Property.
 *
 * Replaces the goals screen, which listed twenty numbered slots whether or not
 * anyone had filled them in -- because the storage was a fixed-length array and
 * the screen showed the storage. This lists what exists.
 *
 * "Goal event definition" rather than "goal": each one names the goal events,
 * rows added when a behaviour pattern matches, rather than being one of twenty
 * slots belonging to a site.
 *
 * PLAN.html calls the same idea a KEY event. Keeping "goal" is deliberate: it
 * is the word 1.x users already have, and it survives the change.
 */
class GoalEventDefinitions extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $propertyId = $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) );

        $this->set( 'propertyId', $propertyId );
        $this->set( 'goalEventDefinitions', self::listForProperty( $propertyId ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'propertyId' => $propertyId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', $propertyId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventDefinitions' );
    }

    /**
     * A Property's goal event definitions.
     *
     * Guarded, because Db::where() drops an empty value rather than matching
     * nothing -- no Property would list every definition on the installation.
     *
     * @param  string $propertyId
     * @return array
     */
    public static function listForProperty( $propertyId ) {

        if ( ! $propertyId ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $propertyId );
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

            $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $definition->setProperties( $row );

            /*
             * The conditions, summarised for the list. Conditions are rows now,
             * so the definition row itself carries none -- reading one off it
             * showed an empty cell for every definition.
             */
            $summary = array();

            foreach ( $definition->loadConditions() as $condition ) {

                $summary[] = array(
                    'property' => $condition->get( 'condition_property' ),
                    'operator' => $condition->get( 'condition_operator' ),
                    'value'    => $condition->get( 'condition_value' ),
                );
            }

            $rows[ $i ]['conditions']       = $summary;
            $rows[ $i ]['condition_match']  = $definition->conditionMatch();
        }

        return $rows;
    }
}
