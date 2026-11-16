<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Goal groups and start conditions are removed.
 *
 * GOAL GROUPS were 1.x's way of arranging numbered goals into metric-set tabs:
 * each group with an active goal became a tab measuring goal{N}Completions and
 * goalValueAll. Neither metric exists on v2, so every tab they produced asked
 * for metrics that cannot resolve. The goal_group column and the goal_groups
 * setting that labelled them go.
 *
 * START CONDITIONS (role = 'start') decided whether a visitor BEGAN a goal. They
 * fed goal_N_start on 1.x's session row and the goalNStarts metrics; nothing on
 * v2 reads them. Where a visitor begins a path is a funnel visualization's
 * question now. The rows are DELETED BEFORE the role column is dropped: without
 * the column a start condition would read as a match condition, and change what
 * the goal event marks.
 *
 * AN UPDATE, because both columns shipped: owa_goal_event and
 * owa_goal_event_condition are 1.13's (Update025).
 *
 * Update025 creates both tables from the current entities, so on an upgrade
 * from before 1.13 neither column exists by the time this runs, and each drop is
 * guarded.
 *
 * THE INVERSE. Before anything is deleted, what goes is kept in the
 * goal_groups_before_056 setting: the start conditions and the goal_groups rows,
 * every column, and each other condition's role and each goal event's
 * goal_group that is not NULL. down() adds the columns back, puts the rows and
 * values back and removes the record. A run of up() that finds nothing to
 * remove changes nothing, so a second run leaves the record of the first;
 * down() with no record restores the columns only.
 *
 * The goal tables are read under the names they had before Update076
 * (namedBefore076()), which is what an upgrade meets here.
 */
class Update056 extends \OWA\Core\Update {

    var $schema_version = 56;

    var $is_cli_mode_required = false;

    /** What up() removed: { conditions: id => row, roles: id => role, groups: id => goal_group, settings: id => row }. */
    const BEFORE = 'goal_groups_before_056';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    function up( $force = false ) {

        $db         = \OWA\Core\CoreAPI::dbSingleton();
        $condition  = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076();
        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();

        $found = array( 'conditions' => array(), 'roles' => array(), 'groups' => array(), 'settings' => array() );

        if ( $this->hasColumn( $condition, 'role' ) ) {

            foreach ( (array) $db->get_results( sprintf( 'SELECT * FROM %s WHERE role IS NOT NULL', $condition->getTableName() ) ) as $row ) {

                $row = (array) $row;

                if ( $row['role'] === 'start' ) {

                    $found['conditions'][ (string) $row['id'] ] = $row;

                } else {

                    $found['roles'][ (string) $row['id'] ] = $row['role'];
                }
            }
        }

        if ( $this->hasColumn( $definition, 'goal_group' ) ) {

            foreach ( (array) $db->get_results( sprintf( 'SELECT id, goal_group FROM %s WHERE goal_group IS NOT NULL',
                    $definition->getTableName() ) ) as $row ) {

                $row = (array) $row;
                $found['groups'][ (string) $row['id'] ] = $row['goal_group'];
            }
        }

        foreach ( (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name = 'goal_groups'",
                $this->settingTable() ) ) as $row ) {

            $row = (array) $row;
            $found['settings'][ (string) $row['id'] ] = $row;
        }

        if ( array_filter( $found ) ) {

            $record = $this->record();

            // The first run's copy is the one down() restores.
            foreach ( $found as $kind => $values ) {

                $record[ $kind ] = ( isset( $record[ $kind ] ) && is_array( $record[ $kind ] ) ? $record[ $kind ] : array() ) + $values;
            }

            // Kept before anything changes, so a run that stops part way can still be undone.
            if ( ! $this->writeRecord( serialize( $record ) ) ) {

                $this->e->notice( sprintf( 'Keeping the goal groups and start conditions in %s failed.', self::BEFORE ) );

                return false;
            }
        }

        if ( $this->hasColumn( $condition, 'role' ) ) {

            $db->query( sprintf( "DELETE FROM %s WHERE role = 'start'",
                $condition->getTableName() ) );
        }

        if ( $this->dropColumnIfPresent( $condition, 'role' ) === false ) {

            $this->e->notice( 'Dropping owa_goal_event_condition.role failed' );

            return false;
        }

        if ( $this->dropColumnIfPresent( $definition, 'goal_group' ) === false ) {

            $this->e->notice( 'Dropping owa_goal_event.goal_group failed' );

            return false;
        }

        $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = 'goal_groups'",
            $this->settingTable() ) );

        return true;
    }

    /**
     * The exact inverse: the columns, then what up() kept. The definitions are
     * spelled out because the entities no longer declare either column.
     */
    function down() {

        $db         = \OWA\Core\CoreAPI::dbSingleton();
        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();
        $condition  = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076();

        if ( $this->addColumnIfMissing( $definition, 'goal_group', OWA_DTD_VARCHAR255 ) === false ) {

            return false;
        }

        if ( $this->addColumnIfMissing( $condition, 'role', OWA_DTD_VARCHAR255 ) === false ) {

            return false;
        }

        // role carried an index; the inverse restores it.
        if ( $db->addIndex( $condition->getTableName(), 'role' ) === false ) {

            return false;
        }

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        foreach ( (array) ( $record['conditions'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( $condition->getTableName(), (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring start condition %s failed.', $row['id'] ?? '' ) );

                return false;
            }
        }

        foreach ( (array) ( $record['roles'] ?? array() ) as $id => $role ) {

            if ( $db->query( sprintf( 'UPDATE %s SET role = ? WHERE id = ?', $condition->getTableName() ),
                    array( $role, (string) $id ) ) === false ) {

                return false;
            }
        }

        foreach ( (array) ( $record['groups'] ?? array() ) as $id => $group ) {

            if ( $db->query( sprintf( 'UPDATE %s SET goal_group = ? WHERE id = ?', $definition->getTableName() ),
                    array( $group, (string) $id ) ) === false ) {

                return false;
            }
        }

        foreach ( (array) ( $record['settings'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( $this->settingTable(), (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring goal_groups row %s failed.', $row['id'] ?? '' ) );

                return false;
            }
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->settingTable() ), array( $this->recordId() ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    private function hasColumn( $entity, $column ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $entity->getTableName(), $column ) );
    }

    private function settingTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    /** What up() kept, or an empty array. */
    private function record() {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT value FROM %s WHERE id = ?', $this->settingTable() ),
            array( $this->recordId() ) );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) ( (array) $row )['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    private function recordId() {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', self::INSTALL_SCOPE_ID, 'base', self::BEFORE );
    }

    /** Upsert the install row of the record. */
    private function writeRecord( $value ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, 0, ?) ON DUPLICATE KEY UPDATE value = ?", $this->settingTable() ),
            array( $this->recordId(), self::INSTALL_SCOPE_ID, self::BEFORE, $value, time(), $value ) ) !== false;
    }

    /** A row put back exactly as SELECT * read it. */
    private function restoreRow( $table, array $row ) {

        $columns = array_keys( $row );

        foreach ( $columns as $column ) {

            // Names the table gave, but they are interpolated, so only plain ones.
            if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {

                return false;
            }
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( (string) $row['id'] ) ) === false ) {

            return false;
        }

        return $db->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ', ', $columns ),
            implode( ', ', array_fill( 0, count( $columns ), '?' ) ) ), array_values( $row ) ) !== false;
    }
}

?>
