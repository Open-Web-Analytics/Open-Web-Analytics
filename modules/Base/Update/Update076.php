<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The goal event tables are named for what they hold: definitions.
 *
 * A goal event is a row of raw named by the definition it met
 * (Classes\GoalMarking). What an author writes on the admin screens -- a name,
 * a trigger, conditions, a count mode and a value -- is a goal event
 * definition, and its storage says so:
 *
 *   goal_event                            -> goal_event_definition
 *   goal_event_condition                  -> goal_event_definition_condition
 *   goal_event_condition.goal_event_id    -> goal_event_definition_id
 *   a funnel step's goal_event_id key     -> goal_event_definition_id
 *     (owa_custom_report.definition)
 *
 * RAW SQL ON NAMES SPELLED OUT HERE, not the entities, which carry the new
 * names and would stop describing this update if a later one renamed them
 * again. The updates before this one read the tables under the old names
 * (Entity\GoalEventDefinition::namedBefore076()).
 *
 * Every step is skipped when it is already done, so up() and down() can each
 * run twice. A table found under BOTH names is refused before anything is
 * renamed, rather than guessed at: either may hold definitions the other does
 * not.
 *
 * Neither direction touches the object cache, which is keyed by table name. An
 * entry cached under the names being restored is stale if a definition changed
 * while the other names were in use; `php cli.php cmd=flush-cache` clears it.
 *
 * NOT CLI-ONLY. A rename is instant, and the funnels are a handful of rows.
 */
class Update076 extends \OWA\Core\Update {

    var $schema_version = 76;

    var $is_cli_mode_required = false;

    /** old name => new name, without the table prefix. */
    const TABLES = array(
        'goal_event'           => 'goal_event_definition',
        'goal_event_condition' => 'goal_event_definition_condition',
    );

    /** The condition table's column naming its definition, and a funnel step's key. */
    const OLD_KEY = 'goal_event_id';
    const NEW_KEY = 'goal_event_definition_id';

    function up( $force = false ) {

        if ( $this->underBothNames() ) {

            return false;
        }

        foreach ( self::TABLES as $old => $new ) {

            if ( ! $this->renameTable( $old, $new ) ) {

                return false;
            }
        }

        return $this->renameColumn( self::TABLES['goal_event_condition'], self::OLD_KEY, self::NEW_KEY )
            && $this->renameStepKeys( self::OLD_KEY, self::NEW_KEY );
    }

    /** The exact inverse, last step first. */
    function down() {

        if ( $this->underBothNames() ) {

            return false;
        }

        if ( ! $this->renameStepKeys( self::NEW_KEY, self::OLD_KEY )
             || ! $this->renameColumn( self::TABLES['goal_event_condition'], self::NEW_KEY, self::OLD_KEY ) ) {

            return false;
        }

        foreach ( array_reverse( self::TABLES, true ) as $old => $new ) {

            if ( ! $this->renameTable( $new, $old ) ) {

                return false;
            }
        }

        return true;
    }

    /** A table's name under the install's prefix. */
    private function table( $name ) {

        return \OWA\Core\Entity::TABLE_PREFIX . $name;
    }

    /**
     * Whether a table is under both its names, said before anything is renamed
     * so neither direction stops part way.
     *
     * @return bool
     */
    private function underBothNames() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $found = false;

        foreach ( self::TABLES as $old => $new ) {

            if ( $db->tableExists( $this->table( $old ) ) && $db->tableExists( $this->table( $new ) ) ) {

                $this->e->notice( sprintf( 'Both %s and %s exist, so neither is renamed. Move the rows of one'
                    . ' into the other, drop it, and run the update again.', $this->table( $old ), $this->table( $new ) ) );

                $found = true;
            }
        }

        return $found;
    }

    /**
     * Rename a table, unless it has been: nothing under $from is done.
     *
     * @param  string $from  without the prefix
     * @param  string $to    without the prefix
     * @return bool
     */
    private function renameTable( $from, $to ) {

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $from = $this->table( $from );
        $to   = $this->table( $to );

        if ( ! $db->tableExists( $from ) ) {

            return true;
        }

        if ( $db->renameTable( $from, $to ) === false ) {

            $this->e->notice( sprintf( 'Renaming %s to %s failed', $from, $to ) );

            return false;
        }

        return true;
    }

    /**
     * Rename the condition table's definition column, unless it has been.
     *
     * RENAME COLUMN rather than CHANGE, so the type, nullability and index stay
     * exactly as they are in both directions. MySQL 8.0 and MariaDB 10.5.2 have
     * it, below what 2.0 runs on (Classes\DatabaseRequirement).
     *
     * @param  string $table  without the prefix
     * @return bool
     */
    private function renameColumn( $table, $from, $to ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table( $table );

        if ( ! $db->tableExists( $table ) || ! $this->hasColumn( $table, $from ) ) {

            return true;
        }

        if ( $this->hasColumn( $table, $to ) ) {

            $this->e->notice( sprintf( '%s has both %s and %s, so neither is renamed.', $table, $from, $to ) );

            return false;
        }

        if ( $db->query( sprintf( 'ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $from, $to ) ) === false ) {

            $this->e->notice( sprintf( 'Renaming %s.%s to %s failed', $table, $from, $to ) );

            return false;
        }

        return true;
    }

    private function hasColumn( $table, $column ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );
    }

    /**
     * Rename the key a funnel step names its definition by, in every saved
     * report's definition.
     *
     * In the stored text, not decoded and encoded again, so nothing else in a
     * definition changes and down() gives back the bytes up() was given. A
     * definition is stored entity-encoded (CustomReports::hydrate()), so the
     * key is quoted either way. Only a quoted name followed by a colon is a key:
     * an author's step name that happens to read goal_event_id is a value, and
     * a quote inside a value is escaped.
     *
     * @return bool
     */
    private function renameStepKeys( $from, $to ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table( 'custom_report' );

        $rows = (array) $db->get_results( sprintf( 'SELECT id, definition FROM %s WHERE definition LIKE ?', $table ),
            array( '%' . $from . '%' ) );

        $renamed = 0;

        foreach ( $rows as $row ) {

            $row        = (array) $row;
            $definition = (string) $row['definition'];
            $rewritten  = self::renameKey( $definition, $from, $to );

            if ( $rewritten === $definition ) {

                continue;
            }

            if ( $db->query( sprintf( 'UPDATE %s SET definition = ? WHERE id = ?', $table ),
                    array( $rewritten, $row['id'] ) ) === false ) {

                $this->e->notice( sprintf( 'Renaming the funnel steps\' %s in report %s failed', $from, $row['id'] ) );

                return false;
            }

            $renamed++;
        }

        if ( $renamed ) {

            $this->e->notice( sprintf( 'Funnel steps: %s is now %s in %d saved %s.', $from, $to, $renamed, \OWA\Core\Lib::plural( $renamed, 'report', 'reports' ) ) );
        }

        return true;
    }

    /**
     * A stored definition with one key renamed wherever it occurs.
     *
     * @param  string $definition  as stored: JSON, its quotes plain or as &quot;
     * @return string
     */
    public static function renameKey( $definition, $from, $to ) {

        return (string) preg_replace(
            '/(?<!\\\\)("|&quot;)' . preg_quote( $from, '/' ) . '\1(\s*):/',
            '${1}' . $to . '${1}${2}:',
            (string) $definition );
    }
}

?>
