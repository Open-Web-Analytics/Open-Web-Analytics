<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or update one goal event definition.
 */
class GoalEventDefinitionSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        // A definition belongs to a Property; without one it would count nowhere.
        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required', array(
            'stopOnError' => true,
            'errorMsg'    => $this->getMsg( 3218 ),
        ) );

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists', array(
            'entity'   => 'base.property',
            'column'   => 'id',
            'errorMsg' => $this->getMsg( 3218 ),
        ) );

        $name = $this->name();

        $this->addValidation( 'name', $name, 'required',
            array( 'errorMsg' => \OWA\Core\CoreAPI::t( 'Name is required.' ) ) );

        if ( $name !== '' ) {

            $problem = \OWA\Module\Base\Entity\GoalEventDefinition::nameProblem( $name );

            if ( $problem === '' && $this->nameTaken( $name ) ) {

                $problem = sprintf( \OWA\Core\CoreAPI::t( 'Another goal event definition is called %s.' ), $name );
            }

            if ( $problem !== '' ) {

                $this->addValidation( 'name', '', 'required', array( 'errorMsg' => $problem ) );
            }
        }

        /*
         * A condition with no value counts nothing, and says nothing about it.
         * This install had a goal in exactly that state -- a type the evaluator
         * has no case for and no URL -- silently never firing since it was
         * made. Refusing it here is the cheapest place to notice.
         */
        /*
         * At least ONE condition with something to compare against.
         *
         * A definition with none deliberately matches nothing -- an empty rule
         * is vacuously true, and counting every event on the site is the worse
         * direction to be wrong in -- so saving one is refused rather than
         * stored inert.
         */
        $values = array_filter( array_map( 'trim',
            array_map( 'strval', (array) $this->getParam( 'conditionValue' ) ) ),
            static function ( $value ) {

                return $value !== '';
            } );

        if ( ! $values && ! \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $this->trigger() ) ) {

            $this->addValidation( 'conditionValue', '', 'required', array(
                'errorMsg' => \OWA\Core\CoreAPI::t( 'Enter a value to compare against.' ) ) );
        }

        /*
         * THE TRIGGER HAS TO BE AN EVENT, and the conditions have to name columns
         * that event carries.
         *
         * Both are gates at ingest now: marking refuses a row whose event_type is
         * not the trigger, and a condition naming a column the row does not have
         * cannot answer, so it never matches. Either mistake produces a goal that
         * says "active" and counts zero for ever -- which is exactly the state
         * this install was already in, and the reason to refuse it at the one
         * point where someone is looking at the screen.
         */
        $trigger = $this->trigger();

        $problem = $trigger === '' && $this->getParam( 'triggerEvent' ) === \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM
            ? \OWA\Core\CoreAPI::t( 'Enter the name of the custom event.' )
            : ( $trigger !== '' ? \OWA\Module\Base\Entity\GoalEventDefinition::triggerProblem( $trigger ) : '' );

        if ( $problem !== '' ) {

            $this->addValidation( 'triggerEvent', '', 'required', array( 'errorMsg' => $problem ) );

            $trigger = '';
        }

        /*
         * NOT THE TRIGGER'S OWN NAME. A goal event is stored under the
         * definition's name, so a goal named newsletter_signup on newsletter_signup
         * would add a second newsletter_signup row for every one the site sent.
         */
        if ( $trigger !== '' && $name !== '' && strcasecmp( $trigger, $name ) === 0 ) {

            $this->addValidation( 'name', '', 'required', array( 'errorMsg' => sprintf(
                \OWA\Core\CoreAPI::t( 'A goal event definition cannot have the name of the event that triggers it, %s.' ), $trigger ) ) );
        }

        if ( $trigger !== '' ) {

            $available = \OWA\Module\Base\Classes\GoalVocabulary::columnsForEvent( $trigger );

            foreach ( (array) $this->getParam( 'conditionProperty' ) as $i => $property ) {

                $property = (string) $property;

                /*
                 * An empty VALUE is a row someone added and left alone --
                 * saveConditions() skips those -- so its property is not a
                 * mistake and must not be reported as one.
                 */
                $value = trim( (string) ( ( (array) $this->getParam( 'conditionValue' ) )[ $i ] ?? '' ) );

                if ( $value === '' || $property === '' || isset( $available[ $property ] ) ) {

                    continue;
                }

                $this->addValidation( 'conditionValue', '', 'required', array(
                    'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'A %s event does not carry %s.' ),
                        $trigger, $property ) ) );
            }
        }

        /*
         * A regex that does not compile matches nothing, for ever, silently.
         *
         * Caught here rather than left to the comparison, which suppresses the
         * warning so a broken pattern does not shout once per tracked event.
         * Suppressing it there is right; letting someone save it is not.
         */
        foreach ( (array) $this->getParam( 'conditionOperator' ) as $i => $operator ) {

            if ( $operator !== \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_REGEX ) {

                continue;
            }

            $pattern = trim( (string) ( ( (array) $this->getParam( 'conditionValue' ) )[ $i ] ?? '' ) );

            if ( $pattern !== '' && @preg_match( \OWA\Module\Base\Entity\GoalEventDefinition::regexPattern( $pattern ), '' ) === false ) {

                $this->addValidation( 'conditionValue', '', 'required', array(
                    'errorMsg' => \OWA\Core\CoreAPI::t( 'The regular expression is not valid.' ),
                ) );
            }
        }

    }

    function action() {

        $propertyId = (string) $this->getParam( 'propertyId' );
        $id         = $this->getParam( 'goalEventDefinitionId' );

        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        if ( $id ) {

            $definition->load( $id );
        }

        $cents = \OWA\Module\Base\Entity\GoalEventDefinition::decimalToCents( $this->getParam( 'value' ) );

        $definition->set( 'property_id', $propertyId );
        $definition->set( 'name', $this->name() );
        $definition->set( 'count_mode', self::countMode( $this->getParam( 'countMode' ) ) );

        $definition->set( 'condition_match',
            $this->getParam( 'conditionMatch' ) === \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY
                ? \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY
                : \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ALL );
        $definition->set( 'value', $cents === null ? 0 : $cents );
        $definition->set( 'is_active', $this->getParam( 'isActive' ) ? 1 : 0 );

        /*
         * The event type the conditions are evaluated against, as chosen.
         *
         * It was fixed at 1.x's one goal type -- and at 1.x's NAME for it -- on
         * the reasoning that the column could be offered as a choice later
         * without a migration. The choice is here now, and the migration was
         * needed anyway: nothing read the column, so every row said
         * base.page_request and marking now gates on it.
         *
         * validate() has already refused anything that is not an event name, so
         * a value reaching this point is one; falling back to the default rather
         * than the submitted value keeps a form with no field at all (an API
         * caller) working.
         */
        $trigger = $this->trigger();

        $definition->set( 'trigger_event_type', $trigger !== '' ? $trigger
            : ( $definition->get( 'trigger_event_type' )
                ?: \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_DEFAULT ) );

        if ( $definition->wasPersisted() ) {

            $definition->update();

        } else {

            $definition->set( 'id', $definition->generateId(
                'goal_event:' . $propertyId . ':' . uniqid( '', true ) ) );
            $definition->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $definition->create();
        }

        $this->saveConditions( $definition->get( 'id' ) );

        $this->set( 'propertyId', $propertyId );
        $this->setRedirectAction( 'base.goalEventDefinitions' );
        $this->set( 'status_code', 3219 );
    }
    /**
     * The count mode to store for what was submitted: one of the two constants,
     * and the default, once per event, for anything else -- the form's default
     * for a new definition, and what an API caller sending nothing gets.
     *
     * @param  mixed $submitted
     * @return string
     */
    public static function countMode( $submitted ) {

        return $submitted === \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION
            ? \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION
            : \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT;
    }

    /**
     * The trigger as submitted: the picker's event, or for "a custom event"
     * the name typed beside it.
     *
     * @return string
     */
    private function trigger() {

        $trigger = (string) $this->getParam( 'triggerEvent' );

        return $trigger === \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM
            ? trim( (string) $this->getParam( 'customTriggerEvent' ) )
            : $trigger;
    }

    /**
     * The definition's name: what was typed for a new one, the stored one for a
     * saved one. Its goal events are stored under it, so a later save cannot
     * change it.
     *
     * @return string
     */
    private function name() {

        $id = $this->getParam( 'goalEventDefinitionId' );

        if ( $id ) {

            $existing = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $existing->load( $id );

            if ( $existing->wasPersisted() && (string) $existing->get( 'name' ) !== '' ) {

                return (string) $existing->get( 'name' );
            }
        }

        return trim( (string) $this->getParam( 'name' ) );
    }

    /** Whether another definition of this Property has the name. */
    private function nameTaken( $name ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT id FROM %s WHERE property_id = ? AND name = ? AND id <> ? LIMIT 1',
            $entity->getTableName() ),
            array( (string) $this->getParam( 'propertyId' ), $name,
                (string) ( $this->getParam( 'goalEventDefinitionId' ) ?: '0' ) ) );

        return ! empty( $row['id'] );
    }

    private function saveConditions( $definitionId ) {

        if ( ! $definitionId ) {

            return;
        }

        /*
         * The old conditions go through the ENTITY, one at a time by id.
         *
         * goal_event_definition_condition is cachable and the cache is persisted
         * to disk, so a raw DELETE by goal_event_definition_id removes the rows and
         * leaves every one of them still answering from cache under its own id
         * key -- a save that appears to work and then keeps counting the
         * conditions it replaced.
         */
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'goal_event_definition_id', $definitionId );

        foreach ( (array) $db->getAllRows() as $old ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )
                ->delete( $old['id'] );
        }

        $properties = (array) $this->getParam( 'conditionProperty' );
        $operators  = (array) $this->getParam( 'conditionOperator' );
        $values     = (array) $this->getParam( 'conditionValue' );

        $number = 0;

        foreach ( $values as $i => $value ) {

            $value = trim( (string) $value );

            /*
             * A row with nothing to compare against is one someone added and
             * left alone. Skipped without advancing the number, so the stored
             * conditions are 1..n with no gaps.
             */
            if ( $value === '' ) {

                continue;
            }

            $number++;

            $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );

            $condition->set( 'id', $condition->generateId(
                'goal_event_condition:' . $definitionId . ':' . $number ) );
            $condition->set( 'goal_event_definition_id', $definitionId );
            $condition->set( 'sort_order', $number );
            $condition->set( 'condition_property', $properties[ $i ] ?? '' );
            $condition->set( 'condition_operator', $operators[ $i ] ?? '' );
            $condition->set( 'condition_value', $value );
            $condition->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $condition->create();
        }
    }
    function errorAction() {

        $propertyId = $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) );

        /* Re-render the form with what was typed, rather than losing it. */
        $this->set( 'goalEventDefinition', array(
            'id'              => $this->getParam( 'goalEventDefinitionId' ),
            'name'            => $this->name(),
            'condition_match' => $this->getParam( 'conditionMatch' ),
            'count_mode'      => $this->getParam( 'countMode' ),
            'value'           => \OWA\Module\Base\Entity\GoalEventDefinition::decimalToCents(
                                     $this->getParam( 'value' ) ) ?: 0,
            'is_active'       => $this->getParam( 'isActive' ) ? 1 : 0,
        ) );

        /*
         * The conditions as SUBMITTED, so a refused form comes back carrying
         * what was typed. Rebuilt from the parallel arrays the form posts --
         * reading them back from the database would show what was there before
         * the edit, which is the opposite of helpful.
         */
        $properties = (array) $this->getParam( 'conditionProperty' );
        $operators  = (array) $this->getParam( 'conditionOperator' );

        $conditions = array();

        foreach ( (array) $this->getParam( 'conditionValue' ) as $i => $value ) {

            $conditions[] = array(
                'condition_property' => $properties[ $i ] ?? '',
                'condition_operator' => $operators[ $i ] ?? '',
                'condition_value'    => $value,
            );
        }

        $this->set( 'conditions', $conditions );

        $this->set( 'goalEventDefinitionId', $this->getParam( 'goalEventDefinitionId' ) );
        $this->set( 'propertyId', $propertyId );
        /*
         * The SUBMITTED trigger, so the re-rendered form offers the vocabulary of
         * the event the author had chosen -- not of the default. Falling back to
         * the default only when nothing was submitted.
         */
        $trigger = $this->trigger();

        /*
         * A custom name that was refused comes back in its field, with the
         * picker on "a custom event", rather than being swapped for the default.
         */
        if ( $trigger === '' || \OWA\Module\Base\Entity\GoalEventDefinition::triggerProblem( $trigger ) !== '' ) {

            $trigger = $this->getParam( 'triggerEvent' ) === \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM
                ? \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM
                : \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_DEFAULT;
        }

        $this->set( 'triggerEvent', $trigger );
        $this->set( 'customTriggerEvent', trim( (string) $this->getParam( 'customTriggerEvent' ) ) );
        $this->set( 'triggerEvents',
            \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames() );
        $this->set( 'customTriggerEvents', GoalEventDefinitionEdit::customEventNames( $propertyId ) );
        $this->set( 'conditionProperties',
            GoalEventDefinitionEdit::conditionProperties( $trigger, $conditions ) );
        $this->set( 'conditionVocabulary', GoalEventDefinitionEdit::vocabulary() );

        /*
         * SAVED means stored, not submitted: the properties the definition's
         * conditions name in the database. A refused NEW definition has none, so
         * nothing on the redrawn form is treated as saved.
         */
        $saved = array();

        if ( $this->getParam( 'goalEventDefinitionId' ) ) {

            $stored = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $stored->load( $this->getParam( 'goalEventDefinitionId' ) );

            foreach ( $stored->get( 'id' ) ? $stored->loadConditions() : array() as $condition ) {

                $saved[] = (string) $condition->get( 'condition_property' );
            }
        }

        $this->set( 'savedProperties', $saved );
        $this->set( 'params', array_merge( (array) $this->params, array( 'propertyId' => $propertyId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', $propertyId ) );
        $this->set( 'error_code', 3002 );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventDefinitionEdit' );
    }
}
