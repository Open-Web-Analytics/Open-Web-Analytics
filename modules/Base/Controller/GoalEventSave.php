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
        $goalEvent->set( 'count_mode',
            $this->getParam( 'countMode' ) === \OWA\Module\Base\Entity\GoalEvent::COUNT_PER_EVENT
                ? \OWA\Module\Base\Entity\GoalEvent::COUNT_PER_EVENT
                : \OWA\Module\Base\Entity\GoalEvent::COUNT_PER_SESSION );

        $goalEvent->set( 'condition_match',
            $this->getParam( 'conditionMatch' ) === \OWA\Module\Base\Entity\GoalEvent::MATCH_ANY
                ? \OWA\Module\Base\Entity\GoalEvent::MATCH_ANY
                : \OWA\Module\Base\Entity\GoalEvent::MATCH_ALL );
        $goalEvent->set( 'value', $cents === null ? 0 : $cents );
        $goalEvent->set( 'is_active', $this->getParam( 'isActive' ) ? 1 : 0 );
        $goalEvent->set( 'goal_group', (string) $this->getParam( 'goalGroup' ) );

        /*
         * The event type the condition is evaluated against. Fixed for now,
         * because 1.x has exactly one implemented goal type and evaluates it
         * against page requests -- but stored per row rather than assumed, so
         * v2 can offer a choice without a migration.
         */
        if ( ! $goalEvent->get( 'trigger_event_type' ) ) {

            $goalEvent->set( 'trigger_event_type',
                \OWA\Module\Base\Entity\GoalEvent::TRIGGER_PAGE_VIEW );
        }

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

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $entity->getTableName() );
        $db->where( 'goal_event_id', $goalEventId );
        $db->executeQuery();

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
    public static function nextFreeSlot( $siteId ) {

        $taken = array();

        foreach ( GoalEvents::listFor( $siteId ) as $row ) {

            if ( (int) $row['goal_number'] > 0 ) {

                $taken[ (int) $row['goal_number'] ] = true;
            }
        }

        for ( $i = 1; $i <= 20; $i++ ) {

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
        $this->set( 'conditionProperties', GoalEventEdit::conditionProperties() );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->set( 'error_code', 3002 );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.goalEventEdit' );
    }
}
