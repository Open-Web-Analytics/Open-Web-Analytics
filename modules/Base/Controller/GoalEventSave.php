<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or update one goal event.
 */
class GoalEventSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A goal event needs a name. It is what reports call it.' ) );

        /*
         * A condition with no value counts nothing, and says nothing about it.
         * This install had a goal in exactly that state -- a type the evaluator
         * has no case for and no URL -- silently never firing since it was
         * made. Refusing it here is the cheapest place to notice.
         */
        /*
         * At least ONE condition with something to compare against.
         *
         * A goal event with none deliberately matches nothing -- an empty rule
         * is vacuously true, and counting every event on the site is the worse
         * direction to be wrong in -- so saving one is refused rather than
         * stored inert.
         */
        $values = array_filter( array_map( 'trim',
            array_map( 'strval', (array) $this->getParam( 'conditionValue' ) ) ),
            static function ( $value ) {

                return $value !== '';
            } );

        if ( ! $values ) {

            $this->addValidation( 'conditionValue', '', 'required', array(
                'errorMsg' => 'Without something to compare against, this would count nothing.' ) );
        }

        /*
         * A renamed group must be given an actual name.
         *
         * The field is optional -- leaving it empty keeps the group's current
         * label -- but a name of nothing but spaces is not "no rename", it is a
         * blank label. Every group with an active goal event becomes a tab on
         * every tabbed report, so a blank name is an unlabelled tab across the
         * whole reporting UI.
         */
        $newGroupName = (string) $this->getParam( 'newGoalGroupName' );

        if ( $newGroupName !== '' && trim( $newGroupName ) === '' ) {

            $this->addValidation( 'newGoalGroupName', '', 'required' );
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
        $trigger = (string) $this->getParam( 'triggerEvent' );

        if ( $trigger !== '' && ! in_array( $trigger,
                \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            $this->addValidation( 'triggerEvent', '', 'required', array(
                'errorMsg' => 'That is not an event OWA collects, so nothing would '
                              . 'ever match it.' ) );

            $trigger = '';
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
                    'errorMsg' => sprintf(
                        'A %s event does not carry %s, so a condition on it would '
                        . 'never match.', $trigger, $property ) ) );
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

            if ( $operator !== \OWA\Module\Base\Entity\GoalEvent::MATCH_REGEX ) {

                continue;
            }

            $pattern = trim( (string) ( ( (array) $this->getParam( 'conditionValue' ) )[ $i ] ?? '' ) );

            if ( $pattern !== '' && @preg_match( '@' . $pattern . '@i', '' ) === false ) {

                $this->addValidation( 'conditionValue', '', 'required', array(
                    'errorMsg' => 'That is not a valid regular expression, so it would never match.',
                ) );
            }
        }

    }

    /** Apply a group rename, if one was typed. */
    private function saveGroupRename( $siteId ) {

        $newGroupName = trim( (string) $this->getParam( 'newGoalGroupName' ) );

        if ( $newGroupName === '' ) {

            return;
        }

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $gm->saveGoalGroupLabel( (int) $this->getParam( 'goalGroup' ), $newGroupName );
        unset( $gm );
    }
    function action() {

        $siteId = $this->getParam( 'siteId' );
        $id     = $this->getParam( 'goalEventId' );

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( $id ) {

            $goalEvent->load( $id );
        }

        $cents = \OWA\Module\Base\Entity\GoalEvent::decimalToCents( $this->getParam( 'value' ) );

        $goalEvent->set( 'property_id',
            \OWA\Module\Base\Classes\GoalManager::propertyFor( $siteId ) );
        $goalEvent->set( 'name', trim( (string) $this->getParam( 'name' ) ) );
        /*
         * ALWAYS once per session, whatever was submitted.
         *
         * A conversion is a column on the session row, so a second match in the
         * same session has nowhere to go -- once per event is a setting the
         * storage can hold and the handler cannot honour. The form offers only
         * the one choice; this is what makes that true of the row as well, so a
         * hand-made request cannot store an intent nothing acts on.
         *
         * Delete this clamp, not the column, when per-event recording lands.
         */
        $goalEvent->set( 'count_mode',
            \OWA\Module\Base\Entity\GoalEvent::COUNT_PER_SESSION );

        $goalEvent->set( 'condition_match',
            $this->getParam( 'conditionMatch' ) === \OWA\Module\Base\Entity\GoalEvent::MATCH_ANY
                ? \OWA\Module\Base\Entity\GoalEvent::MATCH_ANY
                : \OWA\Module\Base\Entity\GoalEvent::MATCH_ALL );
        $goalEvent->set( 'value', $cents === null ? 0 : $cents );
        $goalEvent->set( 'is_active', $this->getParam( 'isActive' ) ? 1 : 0 );
        $goalEvent->set( 'goal_group', (string) $this->getParam( 'goalGroup' ) );

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
        $trigger = (string) $this->getParam( 'triggerEvent' );

        $goalEvent->set( 'trigger_event_type', $trigger !== '' ? $trigger
            : ( $goalEvent->get( 'trigger_event_type' )
                ?: \OWA\Module\Base\Entity\GoalEvent::TRIGGER_DEFAULT ) );

        if ( $goalEvent->wasPersisted() ) {

            $goalEvent->update();

        } else {

            /*
             * A NEW goal event takes the next free numbered slot if there is one,
             * and none at all once twenty are used.
             *
             * The 45 goal{N} metrics resolve by number, so a goal event with a
             * slot can be reported through them and one without cannot. Giving
             * out slots while they last means nothing REGRESSES -- what could
             * be reported before still can -- without capping goal events at
             * twenty the way the slots did.
             */
            $goalEvent->set( 'id', $goalEvent->generateId(
                'goal_event:' . $siteId . ':' . uniqid( '', true ) ) );
            $goalEvent->set( 'goal_number', self::nextFreeSlot( $siteId ) );
            $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $goalEvent->create();
        }

        $this->saveGroupRename( $siteId );
        $this->saveConditions( $goalEvent->get( 'id' ) );

        $this->set( 'siteId', $siteId );
        $this->setRedirectAction( 'base.goalEvents' );
        $this->set( 'status_code', 3201 );
    }
    private function saveConditions( $goalEventId ) {

        if ( ! $goalEventId ) {

            return;
        }

        /*
         * The old conditions go through the ENTITY, one at a time by id.
         *
         * goal_event_condition is cachable and the cache is persisted to disk, so
         * a raw DELETE by goal_event_id removes the rows and leaves every one of
         * them still answering from cache under its own id key -- a save that
         * appears to work and then keeps counting the conditions it replaced.
         */
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'goal_event_id', $goalEventId );

        foreach ( (array) $db->getAllRows() as $old ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' )
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

            $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

            $condition->set( 'id', $condition->generateId(
                'goal_event_condition:' . $goalEventId . ':' . $number ) );
            $condition->set( 'goal_event_id', $goalEventId );
            $condition->set( 'sort_order', $number );
            $condition->set( 'condition_property', $properties[ $i ] ?? '' );
            $condition->set( 'condition_operator', $operators[ $i ] ?? '' );
            $condition->set( 'condition_value', $value );
            $condition->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $condition->create();
        }
    }
    /**
     * The lowest numbered slot not already taken on this site, or null.
     *
     * The ceiling is numGoals, and has to be: that one setting is what sizes
     * every other half of a numbered slot. owa_session carries physical
     * goal_<N>, goal_<N>_start and goal_<N>_value columns for 1..numGoals
     * (Entity\Session), the goal<N>Completions metric family is registered
     * over the same range (Base\Module), and GoalManager::saveGoal() refuses a
     * number above it.
     *
     * This loop used to run to a hardcoded 20 while numGoals was 15, so slots
     * 16-20 were handed out with none of that behind them: ConversionHandlers
     * builds 'goal_' . <number> and sets it on the session, which for 16 names
     * a column that does not exist, so the completion was dropped in silence
     * and no metric could have reported it anyway. That is strictly worse than
     * returning null, which is the deliberate, handled "no numbered slot"
     * state -- the goal event is still created and still works, it just has no
     * numbered metric.
     */
    public static function nextFreeSlot( $siteId ) {

        $taken = array();

        foreach ( GoalEvents::listFor( $siteId ) as $row ) {

            if ( (int) $row['goal_number'] > 0 ) {

                $taken[ (int) $row['goal_number'] ] = true;
            }
        }

        $ceiling = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'numGoals' );

        for ( $i = 1; $i <= $ceiling; $i++ ) {

            if ( ! isset( $taken[ $i ] ) ) {

                return $i;
            }
        }

        return null;
    }

    function errorAction() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        /* Re-render the form with what was typed, rather than losing it. */
        $this->set( 'goalEvent', array(
            'id'              => $this->getParam( 'goalEventId' ),
            'name'            => $this->getParam( 'name' ),
            'condition_match' => $this->getParam( 'conditionMatch' ),
            'count_mode'      => $this->getParam( 'countMode' ),
            'goal_group'      => $this->getParam( 'goalGroup' ),
            'value'           => \OWA\Module\Base\Entity\GoalEvent::decimalToCents(
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

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $this->set( 'goalGroups', $gm->getAllGoalGroupLabels() );

        $this->set( 'goalEventId', $this->getParam( 'goalEventId' ) );
        $this->set( 'siteId', $siteId );
        /*
         * The SUBMITTED trigger, so the re-rendered form offers the vocabulary of
         * the event the author had chosen -- not of the default. Falling back to
         * the default only when nothing was submitted.
         */
        $trigger = (string) $this->getParam( 'triggerEvent' );

        if ( ! in_array( $trigger,
                \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            $trigger = \OWA\Module\Base\Entity\GoalEvent::TRIGGER_DEFAULT;
        }

        $this->set( 'triggerEvent', $trigger );
        $this->set( 'triggerEvents',
            \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames() );
        $this->set( 'conditionProperties',
            GoalEventEdit::conditionProperties( $trigger, $conditions ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->set( 'error_code', 3002 );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventEdit' );
    }
}
