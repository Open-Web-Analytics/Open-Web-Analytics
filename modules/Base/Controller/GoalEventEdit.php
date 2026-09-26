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
        /*
         * The trigger first, because the condition vocabulary depends on it: a
         * click carries element_id and a page view does not, and offering a
         * property the event cannot carry produces a goal that never fires.
         */
        $trigger = (string) $goalEvent->get( 'trigger_event_type' );

        if ( $trigger === '' ) {

            $trigger = \OWA\Module\Base\Entity\GoalEvent::TRIGGER_DEFAULT;
        }

        $this->set( 'triggerEvent', $trigger );
        $this->set( 'triggerEvents',
            \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames() );
        $this->set( 'conditionProperties', self::conditionProperties( $trigger, $conditions ) );

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
     * What a condition can be written against, for this trigger event.
     *
     * Classes\GoalVocabulary answers it: the columns of the stored ROW that the
     * trigger event carries. Three things used to answer this question
     * differently -- this list offered every client and server PROPERTY name,
     * marking matched against the event, and GoalEventPredicate kept its own map
     * of four v1 names -- and none of the three agreed with what gets stored.
     *
     * A COLUMN ALREADY IN USE IS KEPT IN THE LIST even when the trigger does not
     * carry it, and labelled as not carried. Dropping it would silently rewrite
     * the condition to whatever happened to be first in the picker the next time
     * anyone saved the form, which is a worse failure than showing something
     * that cannot match.
     *
     * @param  string $event_name  the trigger event
     * @param  array  $conditions  this goal event's stored conditions
     * @return array  list of { name, label }
     */
    public static function conditionProperties( $event_name, array $conditions = array() ) {

        $available = \OWA\Module\Base\Classes\GoalVocabulary::columnsForEvent( $event_name );

        $out = array();

        foreach ( $available as $name => $label ) {

            $out[] = array( 'name' => $name, 'label' => $label );
        }

        foreach ( $conditions as $condition ) {

            $name = (string) ( $condition['condition_property'] ?? '' );

            if ( $name === '' || isset( $available[ $name ] ) ) {

                continue;
            }

            $out[] = array(
                'name'  => $name,
                'label' => \OWA\Module\Base\Classes\GoalVocabulary::label( $name )
                           . ' -- not carried by ' . $event_name,
            );
        }

        return $out;
    }
}
