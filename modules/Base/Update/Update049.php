<?php

namespace OWA\Module\Base\Update;

/**
 * Goal declarations move to the v2 vocabulary.
 *
 * Every goal event on every install says what it watches in words v2 does not
 * use, so every one of them counts nothing:
 *
 *   trigger_event_type   'base.page_request', a v1 event name. It was read by
 *                        nothing until marking started gating on it, so a goal
 *                        that kept a v1 name there would now match no row at
 *                        all -- the gate and this migration have to ship
 *                        together.
 *
 *   condition_property   a v1 PROPERTY name. Conditions are matched against the
 *                        stored ROW now, and neither page_uri nor medium is a
 *                        column of it. page_uri was a v1 derivation and has been
 *                        deleted outright; medium is tagged_medium.
 *
 * MEASURED, both installs here: every condition in existence names page_uri or
 * medium. That is not a coincidence -- they are what the old builder offered
 * first and what Update025 wrote when it migrated the twenty numbered slots.
 *
 * THE MAP IS Classes\GoalVocabulary, not a table written out here, because the
 * builder and the save validation have to agree with it. A vocabulary that lives
 * in a migration is one that stops being true the moment a column is renamed.
 *
 * A DECLARATION THAT CANNOT BE EXPRESSED SWITCHES ITS GOAL OFF, and is named in
 * the output. The old builder offered every property name there was, including
 * ones with no column at all -- page_type, is_robot, the v1 date parts -- so a
 * condition naming one of those cannot be translated into anything. Left active
 * it would say "active" in the UI and count zero for ever, which is the state
 * this whole change exists to remove. Switched off it is visible, and one click
 * turns it back on once its condition is rewritten.
 *
 * NO down(). The mapping is many-to-one -- page_uri and page_path both arrive at
 * page_path -- so there is no inverse to apply, and guessing one would rewrite
 * conditions that were authored in the new vocabulary in the first place. down()
 * says that rather than pretending.
 *
 * IDEMPOTENT. GoalVocabulary::columnFor() returns a column unchanged, and
 * V2Event::name() leaves a bare v2 name alone, so a second run finds nothing to
 * do.
 */
class Update049 extends \OWA\Core\Update {

    var $schema_version = 49;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        if ( $this->migrateTriggers() === false ) {

            return false;
        }

        return $this->migrateConditions();
    }

    /**
     * v1 event names on the trigger become v2 event names.
     *
     * Through Classes\V2Event::name(), which reads conf/beacon_compat.php -- the
     * one place that knows what a v1 event type is called now. A second copy of
     * that map here is a second thing to keep true.
     *
     * @return bool
     */
    protected function migrateTriggers() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id, trigger_event_type' );

        foreach ( (array) $db->getAllRows() as $row ) {

            $trigger = (string) $row['trigger_event_type'];

            if ( $trigger === '' ) {

                continue;
            }

            $name = \OWA\Module\Base\Classes\V2Event::name( $trigger );

            if ( $name === $trigger ) {

                continue;
            }

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goal->load( $row['id'] );

            if ( ! $goal->wasPersisted() ) {

                continue;
            }

            $goal->set( 'trigger_event_type', $name );

            if ( $goal->update() === false ) {

                $this->e->notice( sprintf(
                    'Migrating the trigger of goal event %s failed', $row['id'] ) );

                return false;
            }

            $this->e->notice( sprintf(
                'Goal event %s now triggers on %s (was %s)',
                $row['id'], $name, $trigger ) );
        }

        return true;
    }

    /**
     * v1 property names on a condition become raw column names.
     *
     * @return bool
     */
    protected function migrateConditions() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id, goal_event_id, condition_property' );

        $unmappable = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $property = (string) $row['condition_property'];

            $column = \OWA\Module\Base\Classes\GoalVocabulary::columnFor( $property );

            if ( $column === null ) {

                $unmappable[ (string) $row['goal_event_id'] ] = $property;

                $this->e->notice( sprintf(
                    'Goal event condition %s tests %s, which is not a column of the '
                    . 'stored row and has no equivalent. Its goal event is being '
                    . 'switched off.', $row['id'], $property ) );

                continue;
            }

            if ( $column === $property ) {

                continue;
            }

            $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $condition->load( $row['id'] );

            if ( ! $condition->wasPersisted() ) {

                continue;
            }

            $condition->set( 'condition_property', $column );

            if ( $condition->update() === false ) {

                $this->e->notice( sprintf(
                    'Migrating goal event condition %s failed', $row['id'] ) );

                return false;
            }

            $this->e->notice( sprintf(
                'Goal event condition %s now tests %s (was %s)',
                $row['id'], $column, $property ) );
        }

        foreach ( array_keys( $unmappable ) as $goal_event_id ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goal->load( $goal_event_id );

            if ( ! $goal->wasPersisted() || ! $goal->isActive() ) {

                continue;
            }

            $goal->set( 'is_active', 0 );

            if ( $goal->update() === false ) {

                $this->e->notice( sprintf(
                    'Switching off goal event %s failed', $goal_event_id ) );

                return false;
            }
        }

        return true;
    }

    function down() {

        $this->e->notice(
            'Nothing to reverse: the vocabulary map is many-to-one, so there is no '
            . 'inverse, and applying a guessed one would rewrite conditions that '
            . 'were authored in the current vocabulary.' );

        return true;
    }
}

?>
