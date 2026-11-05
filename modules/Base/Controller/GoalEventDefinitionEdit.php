<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or edit one goal event definition.
 *
 * Replaces the goal entry screen, which edited a numbered SLOT -- so creating a
 * goal meant picking an unused number out of twenty, and there was no
 * twenty-first. A definition is a row: it is created, not claimed.
 */
class GoalEventDefinitionEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $propertyId = $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) );

        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        if ( $this->getParam( 'goalEventDefinitionId' ) ) {

            $definition->load( $this->getParam( 'goalEventDefinitionId' ) );

        }

        $this->set( 'goalEventDefinition', $definition->_getProperties() );

        $conditions = array();

        foreach ( $definition->get( 'id' ) ? $definition->loadConditions() : array() as $condition ) {

            $conditions[] = $condition->_getProperties();
        }

        $this->set( 'conditions', $conditions );
        /* Whatever it resolved to, so the form saves the row it opened. */
        $this->set( 'goalEventDefinitionId', $definition->get( 'id' ) );
        $this->set( 'propertyId', $propertyId );
        /*
         * The trigger first, because the condition vocabulary depends on it: a
         * click carries element_id and a page view does not, and offering a
         * property the event cannot carry produces a goal that never fires.
         */
        $trigger = (string) $definition->get( 'trigger_event_type' );

        if ( $trigger === '' ) {

            $trigger = \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_DEFAULT;
        }

        $this->set( 'triggerEvent', $trigger );
        $this->set( 'triggerEvents',
            \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames() );
        $this->set( 'customTriggerEvents', self::customEventNames( $propertyId ) );
        $this->set( 'conditionProperties', self::conditionProperties( $trigger, $conditions ) );

        $this->set( 'conditionVocabulary', self::vocabulary() );
        $this->set( 'savedProperties', array_column( $conditions, 'condition_property' ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'propertyId' => $propertyId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', $propertyId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventDefinitionEdit' );
    }

    /**
     * Every trigger's condition properties, so the picker follows the event as
     * it changes (owa.goalbuilder.js). Both screens that render the form set it
     * -- this one and GoalEventDefinitionSave's refused-form path -- from here.
     *
     * @return array  event => list of { name, label, description, carried }
     */
    public static function vocabulary() {

        $vocabulary = array();

        foreach ( \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames() as $event ) {

            $vocabulary[ $event ] = self::conditionProperties( $event );
        }

        /*
         * Every custom event carries the same properties, the ones common to
         * all events, so one list serves them all under the picker's custom
         * value; owa.goalbuilder.js falls back to it for any name not above.
         */
        $vocabulary[ \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM ] =
            self::conditionProperties( \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM );

        return $vocabulary;
    }

    /** How far back customEventNames() looks, in days. */
    const CUSTOM_EVENT_DAYS = 90;

    /** How many custom event names the trigger picker offers. */
    const CUSTOM_EVENT_LIMIT = 100;

    /**
     * The custom events a Property's sites have sent lately, most sent first:
     * what the trigger picker offers beside OWA's own events.
     *
     * Read from the Property's cube over the last CUSTOM_EVENT_DAYS, which the
     * date partitions bound. A name not sent in that window can still be typed
     * in, and a definition's own trigger is always offered (the template adds
     * it).
     *
     * @param  string $propertyId
     * @return string[]
     */
    public static function customEventNames( $propertyId ) {

        if ( ! ctype_digit( (string) $propertyId )
             || ! \OWA\Module\Base\Classes\Cube\Cubes::exists( $propertyId ) ) {

            return array();
        }

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'eventCount' );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( 'eventName' ) );
        $rsm->setPropertyId( (string) $propertyId );
        $rsm->setTimePeriod( 'date_range',
            date( 'Ymd', strtotime( '-' . ( self::CUSTOM_EVENT_DAYS - 1 ) . ' days' ) ), date( 'Ymd' ) );
        $rsm->setConstraints( $rsm->constraintsStringToArray( 'isGoalEvent==0' ) );
        $rsm->setSorts( $rsm->sortStringToArray( 'eventCount-' ) );
        // Room for OWA's own events, which come back too and are dropped below.
        $rsm->setLimit( self::CUSTOM_EVENT_LIMIT + 50 );

        $names = array();

        foreach ( (array) $rsm->getResults()->getResultsRows() as $row ) {

            $name = (string) ( $row['eventName']['value'] ?? '' );

            if ( \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $name ) ) {

                $names[] = $name;
            }
        }

        return array_slice( $names, 0, self::CUSTOM_EVENT_LIMIT );
    }

    /**
     * What a condition can be written against, for this trigger event.
     *
     * Classes\GoalVocabulary answers it: the columns of the stored ROW that the
     * trigger event carries. Three things used to answer this question
     * differently -- this list offered every client and server PROPERTY name,
     * marking matched against the event, and GoalEventDefinitionPredicate kept
     * its own map of four v1 names -- and none of the three agreed with what
     * gets stored.
     *
     * A COLUMN ALREADY IN USE IS KEPT IN THE LIST even when the trigger does not
     * carry it, and labelled as not carried. Dropping it would silently rewrite
     * the condition to whatever happened to be first in the picker the next time
     * anyone saved the form, which is a worse failure than showing something
     * that cannot match.
     *
     * @param  string $event_name  the trigger event
     * @param  array  $conditions  this definition's stored conditions
     * @return array  list of { name, label, description, carried }
     */
    public static function conditionProperties( $event_name, array $conditions = array() ) {

        $available = \OWA\Module\Base\Classes\GoalVocabulary::columnsForEvent( $event_name );

        $out = array();

        foreach ( $available as $name => $label ) {

            $out[] = array( 'name' => $name, 'label' => $label, 'carried' => true,
                'description' => \OWA\Module\Base\Classes\GoalVocabulary::description( $name ) );
        }

        foreach ( $conditions as $condition ) {

            $name = (string) ( $condition['condition_property'] ?? '' );

            if ( $name === '' || isset( $available[ $name ] ) ) {

                continue;
            }

            $out[] = array(
                'name'        => $name,
                'label'       => sprintf( \OWA\Core\CoreAPI::t( '%s -- not carried by %s' ),
                                 \OWA\Module\Base\Classes\GoalVocabulary::label( $name ), $event_name ),
                'carried'     => false,
                'description' => \OWA\Module\Base\Classes\GoalVocabulary::description( $name ),
            );
        }

        return $out;
    }
}
