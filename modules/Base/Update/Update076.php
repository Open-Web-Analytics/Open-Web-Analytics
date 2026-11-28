<?php
namespace OWA\Module\Base\Update;

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
 * run twice.
 *
 * BOTH NAMES. A table can be under its new name before this runs: cmd=core-install
 * creates every missing table from the entities (Module::createMissingTables())
 * before it applies the updates. up() then moves the old table's rows into the
 * new one and drops the old one (moveTable()):
 *
 *   new table empty   it is dropped and the old one renamed, as when only
 *                     the old one exists
 *   both have rows    the old table's rows the new one lacks, by id, are
 *                     copied into it, goal_event_id into
 *                     goal_event_definition_id, and the old table is dropped
 *
 * down() refuses when it finds both names, before anything is renamed.
 *
 * Neither direction touches the object cache, which is keyed by table name. An
 * entry cached under the names being restored is stale if a definition changed
 * while the other names were in use, for the rest of that request.
 *
 * NOT CLI-ONLY. A rename is instant, and the funnels are a handful of rows.
 */
class Update076 extends \OWA\Core\Update {

    public $schema_version = 76;

    /** old name => new name, without the table prefix. */
    const TABLES = [
        'goal_event'           => 'goal_event_definition',
        'goal_event_condition' => 'goal_event_definition_condition',
    ];

    /** The condition table's column naming its definition, and a funnel step's key. */
    const OLD_KEY = 'goal_event_id';
    const NEW_KEY = 'goal_event_definition_id';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        foreach ( self::TABLES as $old => $new ) {

            if ( ! $this->moveTable( $old, $new ) ) {

                return false;
            }
        }

        return $this->renameColumn( self::TABLES['goal_event_condition'], self::OLD_KEY, self::NEW_KEY )
            && $this->renameStepKeys( self::OLD_KEY, self::NEW_KEY );
    }

    /** The exact inverse, last step first. */
    public function down() {

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

    /**
     * Whether a table is under both its names, said before down() renames
     * anything so it does not stop part way.
     *
     * @return bool
     */
    private function underBothNames() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $found = false;

        foreach ( self::TABLES as $old => $new ) {

            if ( $db->tableExists( $this->tableFor( $old ) ) && $db->tableExists( $this->tableFor( $new ) ) ) {

                $this->e->notice( sprintf( 'Both %s and %s exist, so neither is renamed. Move the rows of one'
                    . ' into the other, drop it, and run the update again.', $this->tableFor( $old ), $this->tableFor( $new ) ) );

                $found = true;
            }
        }

        return $found;
    }

    /**
     * The table under $from moved to $to: renamed, or, when $to exists too,
     * its rows moved into $to and it is dropped. Nothing under $from is done.
     *
     * @param  string $from  without the prefix
     * @param  string $to    without the prefix
     * @return bool
     */
    private function moveTable( $from, $to ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $old = $this->tableFor( $from );
        $new = $this->tableFor( $to );

        if ( ! $db->tableExists( $old ) || ! $db->tableExists( $new ) ) {

            return $this->renameTable( $from, $to );
        }

        if ( $db->get_row( sprintf( 'SELECT 1 AS x FROM %s LIMIT 1', $new ) ) ) {

            return $this->mergeTable( $old, $new );
        }

        if ( $db->dropTable( $new ) === false ) {

            $this->e->notice( sprintf( 'Dropping the empty %s failed, so %s is not renamed to it.', $new, $old ) );

            return false;
        }

        $this->e->notice( sprintf( 'Both %1$s and %2$s existed. %2$s had no rows and is dropped.', $old, $new ) );

        return $this->renameTable( $from, $to );
    }

    /**
     * The rows of $old that $new lacks, by id, copied into $new, then $old
     * dropped. A column of $old that $new does not have is not copied, and
     * goal_event_id is copied into goal_event_definition_id.
     *
     * @param  string $old  with the prefix
     * @param  string $new  with the prefix
     * @return bool
     */
    private function mergeTable( $old, $new ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $map = self::columnMap( $this->columns( $old ), $this->columns( $new ) );

        if ( ! isset( $map['id'] ) ) {

            $this->e->notice( sprintf( '%s and %s do not both have an id column, so neither is changed.', $old, $new ) );

            return false;
        }

        $lacking = ' FROM %1$s o LEFT JOIN %2$s n ON n.id = o.id WHERE n.id IS NULL';
        $count   = $db->get_row( sprintf( 'SELECT COUNT(*) AS n' . $lacking, $old, $new ) );

        if ( $db->query( sprintf( 'INSERT INTO %2$s (%3$s) SELECT %4$s' . $lacking, $old, $new,
                implode( ', ', array_keys( $map ) ),
                implode( ', ', array_map( static fn ( $c ) => 'o.' . $c, $map ) ) ) ) === false ) {

            $this->e->notice( sprintf( 'Copying the rows of %s into %s failed. Both are left as they were.', $old, $new ) );

            return false;
        }

        if ( $db->dropTable( $old ) === false ) {

            $this->e->notice( sprintf( 'Dropping %s failed after its rows were copied into %s.', $old, $new ) );

            return false;
        }

        $this->e->notice( sprintf( 'Both %1$s and %2$s existed. %3$d rows of %1$s that %2$s did not have are copied into it, and %1$s is dropped.',
            $old, $new, (int) ( $count['n'] ?? 0 ) ) );

        return true;
    }

    /**
     * Which column of the old table each column of the new one is copied
     * from: the columns both have, and goal_event_id into
     * goal_event_definition_id where the new table has only the second.
     *
     * @param  list<string> $old_columns
     * @param  list<string> $new_columns
     * @return array<string, string> new column => old column
     */
    public static function columnMap( array $old_columns, array $new_columns ) {

        $map = [];

        foreach ( $old_columns as $column ) {

            $to = $column === self::OLD_KEY && ! in_array( self::OLD_KEY, $new_columns, true ) ? self::NEW_KEY : $column;

            if ( in_array( $to, $new_columns, true ) ) {

                $map[ $to ] = $column;
            }
        }

        return $map;
    }

    /**
     * @param  string $table  with the prefix
     * @return list<string> its column names, in order
     */
    private function columns( $table ) {

        return array_values( array_map( static fn ( $row ) => (string) ( (array) $row )['Field'],
            (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf( 'SHOW COLUMNS FROM %s', $table ) ) ) );
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
        $from = $this->tableFor( $from );
        $to   = $this->tableFor( $to );

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
     * @param  string $from
     * @param  string $to
     * @return bool
     */
    private function renameColumn( $table, $from, $to ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( $table );

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

    /**
     * @param  string $table
     * @param  string $column
     * @return bool
     */
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
     * @param  string $from
     * @param  string $to
     * @return bool
     */
    private function renameStepKeys( $from, $to ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( 'custom_report' );

        $rows = (array) $db->get_results( sprintf( 'SELECT id, definition FROM %s WHERE definition LIKE ?', $table ),
            [ '%' . $from . '%' ] );

        $renamed = 0;

        foreach ( $rows as $row ) {

            $row        = (array) $row;
            $definition = (string) $row['definition'];
            $rewritten  = self::renameKey( $definition, $from, $to );

            if ( $rewritten === $definition ) {

                continue;
            }

            if ( $db->query( sprintf( 'UPDATE %s SET definition = ? WHERE id = ?', $table ),
                    [ $rewritten, $row['id'] ] ) === false ) {

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
     * @param  string $from
     * @param  string $to
     * @return string
     */
    public static function renameKey( $definition, $from, $to ) {

        return (string) preg_replace(
            '/(?<!\\\\)("|&quot;)' . preg_quote( $from, '/' ) . '\1(\s*):/',
            '${1}' . $to . '${1}${2}:',
            (string) $definition );
    }
}
