<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Goal groups and start conditions are removed, start conditions first.
 *
 * The rows are written with raw SQL: the entities no longer declare either
 * column, so the fixture has to put the 1.14 shape back itself (down()).
 */
final class Update056Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update056 */
    private $update;

    private string $goalEventId = '';

    private array $conditionIds = [];

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->update = new \OWA\Module\Base\Update\Update056();

        // The 1.14 shape, with both columns.
        $this->assertTrue( $this->update->down() );
    }

    protected function tearDown(): void
    {
        if ( ! $this->update ) {
            return;
        }

        $this->update->up();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->conditionIds as $id ) {
            $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $this->conditionTable(), $db->prepare( $id ) ) );
        }

        if ( $this->goalEventId !== '' ) {
            $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $this->goalEventTable(), $db->prepare( $this->goalEventId ) ) );
        }

        $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = 'goal_groups' AND scope_id = 'owa-update056-probe'",
            \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName() ) );
    }

    /**
     * A start condition is deleted, a match condition on the same goal event is
     * kept, and the columns and the group labels go.
     */
    public function testStartConditionsAndGroupsAreRemovedAndMatchConditionsKept(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->goalEventId = (string) \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )
            ->generateId( 'goal_event:update056-probe:' . uniqid( '', true ) );

        $db->query( sprintf(
            "INSERT INTO %s (id, property_id, name, goal_group, is_active, trigger_event_type)
             VALUES ('%s', 'owa-update056-probe', 'Probe', '2', 1, 'page_view')",
            $this->goalEventTable(), $this->goalEventId ) );

        foreach ( array( 'match' => '', 'start' => 'start' ) as $key => $role ) {

            $id = (string) \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' )
                ->generateId( 'goal_event_condition:' . $this->goalEventId . ':' . $key );

            $this->conditionIds[ $key ] = $id;

            $db->query( sprintf(
                "INSERT INTO %s (id, goal_event_id, sort_order, role, condition_property, condition_operator, condition_value)
                 VALUES ('%s', '%s', 1, '%s', 'page_path', 'exact', '/%s')",
                $this->conditionTable(), $id, $this->goalEventId, $role, $key ) );
        }

        $settings = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $db->query( sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value)
             VALUES ('%s', 'profile', 'owa-update056-probe', 'base', 'goal_groups', '%s')",
            $settings->getTableName(),
            $settings->generateId( 'setting:update056-probe:' . uniqid( '', true ) ),
            $db->prepare( serialize( array( 1 => 'Sale' ) ) ) ) );

        $this->assertCount( 2, $this->conditionRows(), 'the fixture wrote both conditions' );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( array( $this->conditionIds['match'] ),
            array_column( $this->conditionRows(), 'id' ),
            'the start condition must be deleted, not turned into a match condition' );

        $this->assertFalse( $this->hasColumn( $this->conditionTable(), 'role' ) );
        $this->assertFalse( $this->hasColumn( $this->goalEventTable(), 'goal_group' ) );

        $this->assertSame( array(), (array) $db->get_results( sprintf(
            "SELECT id FROM %s WHERE module = 'base' AND name = 'goal_groups' AND scope_id = 'owa-update056-probe'",
            $settings->getTableName() ) ) );
    }

    /** down() restores the shape, role's index included; up() runs twice. */
    public function testDownRestoresTheColumnsAndUpIsRepeatable(): void
    {
        $this->assertTrue( $this->hasColumn( $this->goalEventTable(), 'goal_group' ) );
        $this->assertTrue( $this->hasColumn( $this->conditionTable(), 'role' ) );
        $this->assertTrue( \OWA\Core\CoreAPI::dbSingleton()->indexExists( $this->conditionTable(), 'role' ) );

        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );

        $this->assertFalse( $this->hasColumn( $this->conditionTable(), 'role' ) );
    }

    /* ---------------- helpers ---------------- */

    private function goalEventTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->getTableName();
    }

    private function conditionTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' )->getTableName();
    }

    private function conditionRows(): array
    {
        return array_map( fn( $r ) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT id FROM %s WHERE goal_event_id = '%s'", $this->conditionTable(), $this->goalEventId ) ) );
    }

    private function hasColumn( string $table, string $column ): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );
    }
}
