<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Every saved funnel step becomes an event step.
 *
 * A step was one of two kinds: a page path, or a goal event definition whose
 * trigger and conditions it borrowed as a saved filter. A step is now an event
 * and conditions on it, held in the step itself (Classes\FunnelStep):
 *
 *   { name, path: '/x', step_number }
 *     -> { name, kind: 'event', event: 'page_view', match: 'all',
 *          conditions: [ { property: 'page_path', operator: 'exact', value: '/x' } ],
 *          step_number }
 *
 *     A typed ? is split as the path step's counting split it: page_path exact
 *     the part before it, and page_query exact the part after, when there is one.
 *     The value is stored as typed; the funnel canonicalises a page_path value
 *     when it counts, as it did the path.
 *
 *   { name, goal_event_definition_id, step_number }
 *     -> { name, kind: 'event', event: <its trigger_event_type>,
 *          match: <its condition_match>, conditions: <a copy of its conditions>,
 *          step_number }
 *
 *     A custom trigger is stored as the custom event's own name, so it is
 *     copied like any other.
 *
 * A goal event step is LEFT AS IT IS, and named in the notice by report id,
 * when its definition no longer exists, or when the definition matched nothing
 * -- no trigger, or no conditions on one of OWA's own events. An event step
 * with no conditions matches every event of its name, so a copy would count
 * what the definition did not. VisualizationFunnel refuses to draw a step that
 * is not an event step, and says which.
 *
 * The step key is the one Update076 gave it: on an upgrade from 1.x, Update061
 * writes path and goal event steps, Update076 renames goal_event_id to
 * goal_event_definition_id, and then this runs.
 *
 * THE INVERSE. Each converted report's definition is kept, as it was stored and
 * as this wrote it, in the funnel_steps_before_079 setting, written before the
 * report is. A setting, as Update075 keeps its names, rather than a column on
 * the report: a column is a schema change of its own for down() to undo, and
 * the record is a handful of rows. down() puts back the stored bytes of each
 * report still as this wrote it. A funnel edited since has each step that is
 * still the converted one put back, and the definition encoded again. Then the
 * setting is emptied.
 *
 * RAW SQL ON NAMES SPELLED OUT HERE, and PHP's JSON, not the entities, which
 * are cachable and carry the names of whatever update is latest. A definition
 * is read the way CustomReports::hydrate() reads it.
 *
 * Neither direction touches the object cache, which can still hold a report
 * as it was; `php cli.php cmd=flush-cache` clears it.
 *
 * Each direction runs twice to the same result: up() converts only the steps
 * of the old kinds, and down() reads a record it then empties.
 *
 * NOT CLI-ONLY. Saved funnels are a handful of rows.
 */
class Update079 extends \OWA\Core\Update {

    var $schema_version = 79;

    var $is_cli_mode_required = false;

    /** The setting that keeps each converted report: id => { was, now, steps: index => { was, now } }. */
    const BEFORE = 'funnel_steps_before_079';

    /** The step key a goal event step names its definition by, since Update076. */
    const DEFINITION_KEY = 'goal_event_definition_id';

    function up( $force = false ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $table  = $this->table( 'custom_report' );
        $before = (array) \OWA\Core\CoreAPI::getSetting( 'base', self::BEFORE );

        $rows = (array) $db->get_results( sprintf( 'SELECT id, name, definition FROM %s WHERE definition LIKE ?', $table ),
            array( '%steps%' ) );

        $converted = 0;

        foreach ( $rows as $row ) {

            $row        = (array) $row;
            $id         = (string) $row['id'];
            $stored     = (string) $row['definition'];
            $definition = self::decode( $stored );

            if ( ! is_array( $definition ) ) {

                $this->e->notice( sprintf( 'Custom report "%s" (%s): its definition cannot be read; left as it is.',
                    $row['name'], $id ) );

                continue;
            }

            $result = self::convert( $definition, $this->goalDefinitions( $definition ) );

            foreach ( $result['left'] as $index => $reason ) {

                $this->e->notice( sprintf( 'Funnel "%s" (%s): step %d %s. It is left as it is, and the funnel is not'
                    . ' drawn until the step is replaced.', $row['name'], $id, $index + 1, $reason ) );
            }

            if ( ! $result['steps'] ) {

                continue;
            }

            $text = json_encode( $result['definition'] );

            $record = isset( $before[ $id ] ) && is_array( $before[ $id ] )
                ? $before[ $id ] : array( 'was' => $stored, 'steps' => array() );

            $record['now']   = $text;
            $record['steps'] = (array) $record['steps'] + $result['steps'];

            $before[ $id ] = $record;

            // Kept before the report is written, so a run that stops between the two can still be undone.
            \OWA\Core\CoreAPI::persistSetting( 'base', self::BEFORE, $before );

            if ( $db->query( sprintf( 'UPDATE %s SET definition = ? WHERE id = ?', $table ),
                    array( $text, $id ) ) === false ) {

                $this->e->notice( sprintf( 'Converting the steps of funnel %s failed.', $id ) );

                return false;
            }

            $converted++;
        }

        if ( $converted ) {

            $this->e->notice( sprintf( 'Funnel steps: the steps of %d saved %s are event steps now.', $converted, \OWA\Core\Lib::plural( $converted, 'funnel', 'funnels' ) ) );
        }

        return true;
    }

    /** The exact inverse. See the class comment for a funnel edited since. */
    function down() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table( 'custom_report' );

        foreach ( (array) \OWA\Core\CoreAPI::getSetting( 'base', self::BEFORE ) as $id => $record ) {

            $record = (array) $record;

            $row = (array) $db->get_row( sprintf( 'SELECT definition FROM %s WHERE id = ?', $table ), array( (string) $id ) );

            if ( ! array_key_exists( 'definition', $row ) ) {

                continue;
            }

            $current = (string) $row['definition'];
            $text    = null;

            if ( $current === (string) ( $record['now'] ?? '' ) ) {

                $text = (string) $record['was'];

            } else {

                $definition = self::decode( $current );
                $restored   = 0;

                if ( is_array( $definition ) && isset( $definition['steps'] ) && is_array( $definition['steps'] ) ) {

                    foreach ( (array) ( $record['steps'] ?? array() ) as $index => $step ) {

                        if ( isset( $definition['steps'][ $index ] ) && $definition['steps'][ $index ] == $step['now'] ) {

                            $definition['steps'][ $index ] = $step['was'];
                            $restored++;
                        }
                    }
                }

                $this->e->notice( sprintf( 'Funnel %s was edited after Update079; %d of its %d converted %s put back.',
                    $id, $restored, count( (array) ( $record['steps'] ?? array() ) ),
                    \OWA\Core\Lib::plural( count( (array) ( $record['steps'] ?? array() ) ), 'step was', 'steps were' ) ) );

                if ( $restored ) {

                    $text = json_encode( $definition );
                }
            }

            if ( $text !== null && $db->query( sprintf( 'UPDATE %s SET definition = ? WHERE id = ?', $table ),
                    array( $text, (string) $id ) ) === false ) {

                $this->e->notice( sprintf( 'Restoring the steps of funnel %s failed.', $id ) );

                return false;
            }
        }

        \OWA\Core\CoreAPI::persistSetting( 'base', self::BEFORE, array() );

        return true;
    }

    /**
     * A definition with its path and goal event steps made event steps.
     *
     * Pure, so a test can hand it any shape.
     *
     * @param  array $definition  decoded
     * @param  array $goals       definition id => { trigger, match, conditions }, for
     *                            the definitions the steps name that exist
     * @return array  { definition, steps: index => { was, now }, left: index => reason }
     */
    public static function convert( array $definition, array $goals ) {

        $out = array( 'definition' => $definition, 'steps' => array(), 'left' => array() );

        if ( ! isset( $definition['steps'] ) || ! is_array( $definition['steps'] ) ) {

            return $out;
        }

        foreach ( $definition['steps'] as $index => $step ) {

            if ( ! is_array( $step ) || ( $step['kind'] ?? '' ) === 'event' ) {

                continue;
            }

            if ( array_key_exists( self::DEFINITION_KEY, $step ) ) {

                $id   = (string) $step[ self::DEFINITION_KEY ];
                $goal = $goals[ $id ] ?? null;

                if ( $goal === null ) {

                    $out['left'][ $index ] = sprintf( 'names goal event definition %s, which no longer exists', $id );

                    continue;
                }

                $trigger = (string) $goal['trigger'];

                if ( $trigger === '' || ( ! $goal['conditions']
                     && ! \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $trigger ) ) ) {

                    $out['left'][ $index ] = sprintf( 'names goal event definition %s, which matches nothing', $id );

                    continue;
                }

                $now = self::replaceKey( $step, self::DEFINITION_KEY, array(
                    'kind'       => 'event',
                    'event'      => $trigger,
                    'match'      => $goal['match'] === 'any' ? 'any' : 'all',
                    'conditions' => array_values( $goal['conditions'] ),
                ) );

            } else {

                $now = self::replaceKey( $step, 'path', self::pathStep( (string) ( $step['path'] ?? '' ) ) );
            }

            $out['definition']['steps'][ $index ] = $now;
            $out['steps'][ $index ] = array( 'was' => $step, 'now' => $now );
        }

        return $out;
    }

    /**
     * A path as an event step's fields: a page_view, page_path exact the part
     * before any ?, and page_query exact the part after it when there is one.
     *
     * @param  string $path  as the step stored it
     * @return array
     */
    public static function pathStep( $path ) {

        $query = '';
        $mark  = strpos( $path, '?' );

        if ( $mark !== false ) {

            $query = substr( $path, $mark + 1 );
            $path  = substr( $path, 0, $mark );
        }

        $conditions = array( array( 'property' => 'page_path', 'operator' => 'exact', 'value' => $path ) );

        if ( $query !== '' ) {

            $conditions[] = array( 'property' => 'page_query', 'operator' => 'exact', 'value' => $query );
        }

        return array( 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => $conditions );
    }

    /** A step with one key replaced by these fields, where it stood; a step without it gets them before step_number. */
    private static function replaceKey( array $step, $key, array $fields ) {

        $out = array();

        if ( ! array_key_exists( $key, $step ) ) {

            $at  = array_search( 'step_number', array_keys( $step ), true );
            $key = $at === false ? null : 'step_number';

            if ( $key === null ) {

                return $step + $fields;
            }

            $fields['step_number'] = $step['step_number'];
        }

        foreach ( $step as $name => $value ) {

            if ( $name === $key ) {

                $out += $fields;

                continue;
            }

            $out[ $name ] = $value;
        }

        return $out;
    }

    /**
     * The goal event definitions a definition's steps name, read under the
     * names Update076 gave the tables: id => { trigger, match, conditions }.
     */
    private function goalDefinitions( array $definition ) {

        $ids = array();

        foreach ( (array) ( $definition['steps'] ?? array() ) as $step ) {

            if ( is_array( $step ) && ( $step['kind'] ?? '' ) !== 'event' && isset( $step[ self::DEFINITION_KEY ] ) ) {

                $ids[] = (string) $step[ self::DEFINITION_KEY ];
            }
        }

        $db          = \OWA\Core\CoreAPI::dbSingleton();
        $definitions = $this->table( 'goal_event_definition' );
        $conditions  = $this->table( 'goal_event_definition_condition' );

        if ( ! $ids || ! $db->tableExists( $definitions ) ) {

            return array();
        }

        $out = array();

        foreach ( array_unique( $ids ) as $id ) {

            $row = (array) $db->get_row( sprintf( 'SELECT trigger_event_type, condition_match FROM %s WHERE id = ?',
                $definitions ), array( $id ) );

            if ( ! array_key_exists( 'trigger_event_type', $row ) ) {

                continue;
            }

            $list = array();

            if ( $db->tableExists( $conditions ) ) {

                foreach ( (array) $db->get_results( sprintf( 'SELECT condition_property, condition_operator, condition_value'
                        . ' FROM %s WHERE goal_event_definition_id = ? ORDER BY sort_order, id', $conditions ),
                        array( $id ) ) as $condition ) {

                    $condition = (array) $condition;

                    $list[] = array(
                        'property' => (string) $condition['condition_property'],
                        'operator' => (string) $condition['condition_operator'],
                        'value'    => (string) $condition['condition_value'],
                    );
                }
            }

            $out[ $id ] = array(
                'trigger'    => (string) $row['trigger_event_type'],
                'match'      => (string) $row['condition_match'],
                'conditions' => $list,
            );
        }

        return $out;
    }

    /** A table's name under the install's prefix. */
    private function table( $name ) {

        return \OWA\Core\Entity::TABLE_PREFIX . $name;
    }

    /** A stored definition decoded, as CustomReports::hydrate() reads one. */
    private static function decode( $stored ) {

        return json_decode( html_entity_decode( (string) $stored, ENT_QUOTES ), true );
    }
}

?>
