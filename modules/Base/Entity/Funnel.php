<?php

namespace OWA\Module\Base\Entity;

/**
 * A funnel: an ordered path, and the goal event reaching the end of it counts as.
 *
 * LOOSELY COUPLED TO ITS KEY EVENT, on purpose. The first cut made steps a child
 * of the goal event -- a funnel belonged to one and could not exist without it.
 * That is the wrong way round:
 *
 *   - A funnel is worth having on its own. "Where do people drop out of
 *     checkout" is a question about a path, and answering it should not require
 *     first declaring something a conversion.
 *   - Two funnels can lead to the same goal event. Paid and organic arrivals
 *     reaching the same signup are two paths worth comparing, not one funnel.
 *   - And a goal event should not carry a shape it does not need. Most do not
 *     have a funnel at all.
 *
 * So the funnel names the goal event, and the goal event knows nothing about it.
 * goal_event_id is nullable: a funnel with none is a path analysis, and one whose
 * goal event is later deleted becomes one rather than breaking.
 *
 * This is also GA's shape -- funnel explorations are independent analyses that
 * reference events, not a property of a conversion.
 */
class Funnel extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'funnel' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /* The Observation Profile, as the tracking site_id string. See GoalEvent. */
        $site_id = new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR255 );
        $site_id->setIndex();
        $this->setProperty( $site_id );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /*
         * What reaching the end of this funnel counts as. NULLABLE -- a funnel
         * without one is a path analysis, which is a legitimate thing to want.
         */
        $goal_event_id = new \OWA\Module\Base\Classes\DbColumn( 'goal_event_id', OWA_DTD_BIGINT );
        $goal_event_id->setIndex();
        $this->setProperty( $goal_event_id );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /**
     * This funnel's steps, in order, keyed from their stored step number.
     *
     * Keyed by the STORED number rather than by position: the 1.x conversion
     * evaluator indexes funnel_steps[1] directly, so renumbering from 0 would
     * make every funnel's first step the second one.
     *
     * @return array
     */
    public function loadSteps() {

        if ( ! $this->get( 'id' ) ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'funnel_id', $this->get( 'id' ) );
        /*
         * ASC explicitly, and this one is not cosmetic: orderBy() with no
         * direction is DESC, so the funnel came back last step first -- the
         * report drew Pricing before Home and checkGoalStart tested the wrong
         * end of the path. An ORDERED list is the whole point of this table.
         */
        $db->orderBy( 'step_number', OWA_SQL_ASCENDING );

        $steps = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $step = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );
            $step->setProperties( $row );

            $steps[ (int) $row['step_number'] ] = $step->toStepArray();
        }

        return $steps;
    }

    /**
     * The funnel that counts as one goal event, if any.
     *
     * @return \OWA\Module\Base\Entity\Funnel
     */
    public static function forGoalEvent( $goalEventId ) {

        $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );

        if ( $goalEventId ) {

            $funnel->getByColumn( 'goal_event_id', $goalEventId );
        }

        return $funnel;
    }
}
