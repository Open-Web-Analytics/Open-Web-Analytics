<?php
namespace OWA\Module\Base\Update;

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

    use SettingRows;

    public $schema_version = 56;

    /** What up() removed: { conditions: id => row, roles: id => role, groups: id => goal_group, settings: id => row }. */
    const BEFORE = 'goal_groups_before_056';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db         = \OWA\Core\CoreAPI::dbSingleton();
        $condition  = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076();
        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();

        $found = [ 'conditions' => [], 'roles' => [], 'groups' => [], 'settings' => [] ];

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

                $record[ $kind ] = ( isset( $record[ $kind ] ) && is_array( $record[ $kind ] ) ? $record[ $kind ] : [] ) + $values;
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
    public function down() {

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

        foreach ( (array) ( $record['conditions'] ?? [] ) as $row ) {

            if ( ! $this->restoreRow( (array) $row, $condition->getTableName() ) ) {

                $this->e->notice( sprintf( 'Restoring start condition %s failed.', $row['id'] ?? '' ) );

                return false;
            }
        }

        foreach ( (array) ( $record['roles'] ?? [] ) as $id => $role ) {

            if ( $db->query( sprintf( 'UPDATE %s SET role = ? WHERE id = ?', $condition->getTableName() ),
                    [ $role, (string) $id ] ) === false ) {

                return false;
            }
        }

        foreach ( (array) ( $record['groups'] ?? [] ) as $id => $group ) {

            if ( $db->query( sprintf( 'UPDATE %s SET goal_group = ? WHERE id = ?', $definition->getTableName() ),
                    [ $group, (string) $id ] ) === false ) {

                return false;
            }
        }

        foreach ( (array) ( $record['settings'] ?? [] ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring goal_groups row %s failed.', $row['id'] ?? '' ) );

                return false;
            }
        }

        if ( ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /**
     * @param  \OWA\Core\Entity $entity
     * @param  string          $column
     * @return bool
     */
    private function hasColumn( $entity, $column ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $entity->getTableName(), $column ) );
    }
}
