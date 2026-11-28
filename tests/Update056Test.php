<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Goal groups and start conditions are removed, start conditions first.
 *
 * The rows are written with raw SQL: the entities no longer declare either
 * column, so the fixture has to put the 1.14 shape back itself (down()).
 *
 * On the goal tables under the names they had before Update076, which is what
 * an upgrade meets here: Update076 is rolled back first and applied again last.
 */
final class Update056Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update056 */
    private $update;

    /** @var \OWA\Module\Base\Update\Update076 */
    private $renames;

    private string $goalEventId = '';

    private array $conditionIds = [];

    /** @var array|null the install's record of Update056 before the test, every column */
    private ?array $record = null;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT * FROM %s WHERE id = ?', $this->settingTable() ),
            [ $this->recordId() ] );
        $this->record = $row ? (array) $row : null;

        $this->renames = new \OWA\Module\Base\Update\Update076();
        $this->assertTrue( $this->renames->down(), 'the goal tables under their names before Update076' );

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

        // The record as the test found it: up() above kept the fixture in it.
        $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->settingTable() ), [ $this->recordId() ] );

        if ( $this->record !== null ) {
            $db->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->settingTable(), implode( ', ', array_keys( $this->record ) ),
                implode( ', ', array_fill( 0, count( $this->record ), '?' ) ) ), array_values( $this->record ) );
        }

        $this->assertTrue( $this->renames->up(), 'the goal tables renamed back' );
    }

    /**
     * A start condition is deleted, a match condition on the same goal event is
     * kept, and the columns and the group labels go.
     */
    public function testStartConditionsAndGroupsAreRemovedAndMatchConditionsKept(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->seed();

        $this->assertCount( 2, $this->conditionRows(), 'the fixture wrote both conditions' );

        $this->assertTrue( $this->update->up() );

        $this->assertSame( [ $this->conditionIds['match'] ],
            array_column( $this->conditionRows(), 'id' ),
            'the start condition must be deleted, not turned into a match condition' );

        $this->assertFalse( $this->hasColumn( $this->conditionTable(), 'role' ) );
        $this->assertFalse( $this->hasColumn( $this->goalEventTable(), 'goal_group' ) );

        $this->assertSame( [], (array) $db->get_results( sprintf(
            "SELECT id FROM %s WHERE module = 'base' AND name = 'goal_groups' AND scope_id = 'owa-update056-probe'",
            $this->settingTable() ) ) );
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

    /**
     * down() puts back every row and value up() removed, and removes its record.
     *
     * @covers-setting base.goal_groups_before_056
     */
    public function testDownIsTheExactInverse(): void
    {
        $this->seed();
        $before = $this->fixtureRows();
        $this->assertCount( 4, $before, 'two conditions, the goal event, the labels' );

        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up(), 'up again' );
        $this->assertCount( 2, $this->fixtureRows(), 'the start condition and the labels are gone' );

        $this->assertTrue( $this->update->down() );
        $this->assertSame( $before, $this->fixtureRows(), 'the exact inverse' );
        $this->assertNull( \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT id FROM %s WHERE id = ?', $this->settingTable() ),
            [ $this->recordId() ] ) );

        $this->assertTrue( $this->update->down(), 'down again' );
        $this->assertSame( $before, $this->fixtureRows() );
    }

    /** The record is declared, so nothing refuses to read it back. */
    public function testTheRecordIsDeclared(): void
    {
        $this->assertTrue( \OWA\Core\CoreAPI::configSingleton()->isRegistered( 'base', \OWA\Module\Base\Update\Update056::BEFORE ) );
    }

    /* ---------------- helpers ---------------- */

    /** A goal event in group 2 with a match and a start condition, and a Profile's goal group labels. */
    private function seed(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->goalEventId = (string) \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )
            ->generateId( 'goal_event:update056-probe:' . uniqid( '', true ) );

        $db->query( sprintf(
            "INSERT INTO %s (id, property_id, name, goal_group, is_active, trigger_event_type)
             VALUES ('%s', 9056, 'Probe', '2', 1, 'page_view')",
            $this->goalEventTable(), $this->goalEventId ) );

        foreach ( [ 'match' => '', 'start' => 'start' ] as $key => $role ) {

            $id = (string) \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )
                ->generateId( 'goal_event_condition:' . $this->goalEventId . ':' . $key );

            $this->conditionIds[ $key ] = $id;

            $db->query( sprintf(
                "INSERT INTO %s (id, goal_event_id, sort_order, role, condition_property, condition_operator, condition_value)
                 VALUES ('%s', '%s', 1, '%s', 'page_path', 'exact', '/%s')",
                $this->conditionTable(), $id, $this->goalEventId, $role, $key ) );
        }

        $settings = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $db->query( sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)
             VALUES ('%s', 'profile', 'owa-update056-probe', 'base', 'goal_groups', '%s', 0, 1)",
            $settings->getTableName(),
            $settings->generateId( 'setting:update056-probe:' . uniqid( '', true ) ),
            $db->prepare( serialize( [ 1 => 'Sale' ] ) ) ) );
    }

    /** @return array<string,array> every row this test wrote, every column, keyed by table and id */
    private function fixtureRows(): array
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = [];

        foreach ( (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE goal_event_id = '%s' ORDER BY id",
                $this->conditionTable(), $this->goalEventId ) ) as $row ) {
            $out[ 'condition:' . ( (array) $row )['id'] ] = (array) $row;
        }

        foreach ( (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE id = '%s'", $this->goalEventTable(), $this->goalEventId ) ) as $row ) {
            $out[ 'goal_event:' . ( (array) $row )['id'] ] = (array) $row;
        }

        foreach ( (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name = 'goal_groups' AND scope_id = 'owa-update056-probe'",
                $this->settingTable() ) ) as $row ) {
            $out[ 'setting:' . ( (array) $row )['id'] ] = (array) $row;
        }

        return $out;
    }

    private function settingTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    private function recordId(): string
    {
        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', '1', 'base', \OWA\Module\Base\Update\Update056::BEFORE );
    }

    private function goalEventTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076()->getTableName();
    }

    private function conditionTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076()->getTableName();
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
