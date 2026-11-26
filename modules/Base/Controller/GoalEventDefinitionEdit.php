<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or edit one goal event definition.
 *
 * A definition is a row: it is created, not claimed from a numbered slot.
 */
class GoalEventDefinitionEdit extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $propertyId = $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) );

        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        if ( $this->getParam( 'goalEventDefinitionId' ) ) {

            $definition->load( $this->getParam( 'goalEventDefinitionId' ) );

        }

        $this->set( 'goalEventDefinition', $definition->_getProperties() );

        $conditions = [];

        foreach ( $definition->get( 'id' ) ? $definition->loadConditions() : [] as $condition ) {

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

        // Typed on the form when the trigger is a custom event; nothing to show here.
        $this->set( 'customTriggerEvent', '' );

        $this->settingsChrome( [ 'propertyId' => $propertyId ], '', $propertyId );
        $this->setSubview( 'base.goalEventDefinitionEdit', self::screen( $definition->get( 'id' ) ) );
    }

    /**
     * The form, which GoalEventDefinitionSave draws again for a refused save.
     * The template reads every one of these: a var never set is a blank screen.
     *
     * @param string $definitionId empty for a definition not yet saved
     * @return array<string, mixed> as setSubview() takes it
     */
    public static function screen( $definitionId ) {

        return [
            'template' => 'goal_event_definition_edit.php',
            'vars'     => [
                'headline' => $definitionId ? \OWA\Core\CoreAPI::t( 'Goal Event Definition' ) : \OWA\Core\CoreAPI::t( 'New Goal Event Definition' ),
                'goalEventDefinition', 'goalEventDefinitionId', 'propertyId', 'conditions',
                // The trigger, the events it can be, and every trigger's properties,
                // so the picker follows the event (owa.goalbuilder.js).
                'triggerEvent', 'triggerEvents', 'customTriggerEvents', 'customTriggerEvent',
                'conditionProperties', 'conditionVocabulary', 'savedProperties',
            ],
        ];
    }

    /**
     * Every trigger's condition properties, so the picker follows the event as
     * it changes (owa.goalbuilder.js). Both screens that render the form set it
     * -- this one and GoalEventDefinitionSave's refused-form path -- from here.
     *
     * @return array<string, list<array<string, mixed>>> event => list of { name, label, description, carried, group, group_label }
     */
    public static function vocabulary() {

        $vocabulary = [];

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

            return [];
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

        $names = [];

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
     * trigger event carries.
     *
     * A COLUMN ALREADY IN USE IS KEPT IN THE LIST even when the trigger does not
     * carry it, and labelled as not carried. Dropping it would silently rewrite
     * the condition to whatever happened to be first in the picker the next time
     * anyone saved the form, which is a worse failure than showing something
     * that cannot match.
     *
     * @param  string $event_name  the trigger event
     * @param  array<mixed> $conditions  this definition's stored conditions
     * @return list<array<string, mixed>> list of { name, label, description, carried, group, group_label }
     */
    public static function conditionProperties( $event_name, array $conditions = [] ) {

        $available = \OWA\Module\Base\Classes\GoalVocabulary::columnsForEvent( $event_name );

        $out = [];

        foreach ( $available as $name => $label ) {

            $out[] = self::property( $name, $label, true );
        }

        foreach ( $conditions as $condition ) {

            $name = (string) ( $condition['condition_property'] ?? '' );

            if ( $name === '' || isset( $available[ $name ] ) ) {

                continue;
            }

            $out[] = self::property( $name, sprintf( \OWA\Core\CoreAPI::t( '%s -- not carried by %s' ),
                \OWA\Module\Base\Classes\GoalVocabulary::label( $name ), $event_name ), false );
        }

        return $out;
    }

    /**
     * One property as the builder's field chooser takes it.
     *
     * @param  string $name
     * @param  string $label
     * @param  bool   $carried
     * @return array{name: string, label: string, carried: bool, description: string, group: string, group_label: string}
     */
    private static function property( $name, $label, $carried ) {

        // A column a module added, which nothing groups, is an event's.
        $group = \OWA\Module\Base\Classes\GoalVocabulary::group( $name ) ?: 'event';

        return [
            'name'        => (string) $name,
            'label'       => (string) $label,
            'carried'     => (bool) $carried,
            'description' => \OWA\Module\Base\Classes\GoalVocabulary::description( $name ),
            'group'       => $group,
            'group_label' => \OWA\Module\Base\Classes\FieldGroups::label( $group ),
        ];
    }
}
