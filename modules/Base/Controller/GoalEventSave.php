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
        $this->addValidation( 'conditionValue', trim( (string) $this->getParam( 'conditionValue' ) ),
            'required',
            array( 'errorMsg' => 'Without something to compare against, this would count nothing.' ) );

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

        $this->validateFunnelSteps();
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

    /**
     * The funnel's steps.
     *
     * Carried over from the goal screen this replaces rather than rewritten:
     * every rule below was earned by a bug, and retiring the screen without
     * them would have dropped the lot silently.
     */
    public function validateFunnelSteps() {

        $names = (array) $this->getParam( 'stepName' );
        $paths = (array) $this->getParam( 'stepPath' );

        foreach ( $paths as $i => $path ) {

            $name = trim( (string) ( $names[ $i ] ?? '' ) );
            $path = trim( (string) $path );

            $number = $i + 1;

            /*
             * A step with nothing in it is a row someone added and left alone,
             * not a mistake. Dropped rather than refused.
             */
            if ( $name === '' && $path === '' ) {

                continue;
            }

            /*
             * Anything else has at least one value, so a missing name or path
             * is a HALF-FILLED step -- a mistake worth reporting. Each step is
             * checked; an earlier bad one used to abandon validation and hide
             * every later step along with the rest of the rules.
             */
            $this->addValidation( 'stepName' . $number, $name, 'required',
                array( 'errorMsg' => sprintf( 'Step %s needs a name.', $number ) ) );

            $this->addValidation( 'stepPath' . $number, $path, 'required',
                array( 'errorMsg' => sprintf( 'Step %s needs a path.', $number ) ) );

            /*
             * A path, not a URL, and said so rather than left to fail quietly.
             *
             * Every consumer treats this as a path: the funnel report builds
             * `pagePath == <this>` and checkGoalStart matches it against the
             * event's page_uri. A full URL therefore matches nothing -- the
             * funnel reports zero and the goal event never starts, with nothing
             * logged.
             *
             * Refused rather than silently trimmed to its path: quietly
             * rewriting what someone typed is how they end up not knowing what
             * is stored.
             */
            if ( $path !== '' && preg_match( '~^[a-z][a-z0-9+.\-]*://~i', $path ) ) {

                $this->addValidation( 'stepPath' . $number, '', 'required', array(
                    'errorMsg' => sprintf(
                        'Step %s: enter the page PATH, such as /basket -- not a full web address. '
                        . 'Funnel steps are matched on the path alone.', $number ),
                ) );
            }
        }
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
        $goalEvent->set( 'condition_property', $this->getParam( 'conditionProperty' ) );
        $goalEvent->set( 'condition_operator', $this->getParam( 'conditionOperator' ) );
        $goalEvent->set( 'condition_value', trim( (string) $this->getParam( 'conditionValue' ) ) );
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
        $this->saveFunnel( $goalEvent->get( 'id' ) );

        $this->set( 'siteId', $siteId );
        $this->setRedirectAction( 'base.goalEvents' );
        $this->set( 'status_code', 3201 );
    }

    /**
     * Replace this goal event's funnel with what was submitted.
     *
     * Delete-then-write rather than a diff. A funnel is ORDERED and short, and
     * its identity is its position -- there is no stable key to match a
     * submitted step against a stored one, so a diff would have to invent one.
     * Removing a middle step renumbers everything after it, which a diff would
     * see as several edits rather than one removal.
     *
     * Safe here in a way it is not for grants: nothing else references a step,
     * and the whole list is always submitted by the form that owns it.
     */
    private function saveFunnel( $goalEventId ) {

        if ( ! $goalEventId ) {

            return;
        }

        $paths = (array) $this->getParam( 'stepPath' );
        $names = (array) $this->getParam( 'stepName' );

        $funnel = \OWA\Module\Base\Entity\Funnel::forGoalEvent( $goalEventId );

        $kept = array();

        foreach ( $paths as $i => $path ) {

            $path = trim( (string) $path );

            /*
             * A step with no path is a row someone added and left alone, not a
             * mistake -- the same rule the old goal screen applied. Numbering
             * only advances for steps that are kept, so the stored funnel is
             * 1..n with no gaps.
             */
            if ( $path === '' ) {

                continue;
            }

            $kept[] = array( 'name' => trim( (string) ( $names[ $i ] ?? '' ) ), 'path' => $path );
        }

        if ( ! $kept ) {

            /*
             * Emptying the funnel removes it. A funnel with no steps describes
             * no path, and leaving one behind would put an empty entry in the
             * funnel list that nothing could be done with.
             */
            if ( $funnel->wasPersisted() ) {

                $this->deleteSteps( $funnel->get( 'id' ) );
                $funnel->delete( $funnel->get( 'id' ) );
            }

            return;
        }

        if ( ! $funnel->wasPersisted() ) {

            $funnel->set( 'id', $funnel->generateId(
                'funnel:' . $this->getParam( 'siteId' ) . ':' . $goalEventId ) );
            $funnel->set( 'property_id',
                \OWA\Module\Base\Classes\GoalManager::propertyFor( $this->getParam( 'siteId' ) ) );
            $funnel->set( 'name', trim( (string) $this->getParam( 'name' ) ) );
            $funnel->set( 'goal_event_id', $goalEventId );
            $funnel->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $funnel->create();
        }

        /*
         * Delete-then-write rather than a diff. A funnel is ORDERED and short,
         * and its identity is its position -- there is no stable key to match a
         * submitted step against a stored one, so a diff would have to invent
         * one. Removing a middle step renumbers everything after it, which a
         * diff would read as several edits rather than one removal.
         */
        $this->deleteSteps( $funnel->get( 'id' ) );

        $number = 0;

        foreach ( $kept as $keptStep ) {

            $number++;

            $step = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

            $step->set( 'id', $step->generateId(
                'funnel_step:' . $funnel->get( 'id' ) . ':' . $number ) );
            $step->set( 'funnel_id', $funnel->get( 'id' ) );
            $step->set( 'step_number', $number );
            $step->set( 'name', $keptStep['name'] );
            $step->set( 'condition_property',
                \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI );
            $step->set( 'condition_operator',
                \OWA\Module\Base\Entity\GoalEvent::MATCH_REGEX );
            $step->set( 'condition_value', $keptStep['path'] );
            $step->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $step->create();
        }
    }

    /** Every step of one funnel. */
    private function deleteSteps( $funnelId ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $entity->getTableName() );
        $db->where( 'funnel_id', $funnelId );
        $db->executeQuery();
    }

    /**
     * The lowest numbered slot this Profile is not using, or null past twenty.
     *
     * @return int|null
     */
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
            'id'                 => $this->getParam( 'goalEventId' ),
            'name'               => $this->getParam( 'name' ),
            'condition_property' => $this->getParam( 'conditionProperty' ),
            'condition_operator' => $this->getParam( 'conditionOperator' ),
            'condition_value'    => $this->getParam( 'conditionValue' ),
            'value'              => \OWA\Module\Base\Entity\GoalEvent::decimalToCents(
                                        $this->getParam( 'value' ) ) ?: 0,
            'is_active'          => $this->getParam( 'isActive' ) ? 1 : 0,
        ) );

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
