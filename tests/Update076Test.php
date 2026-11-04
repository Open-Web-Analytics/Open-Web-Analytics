<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update076: the goal event tables, their condition column and a funnel step's
 * key are named for goal event definitions.
 *
 * down() is exercised, and up() runs again after it, so the database the suite
 * runs against ends where it started. Both rewrite every saved report naming
 * the key, which on the suite's database is these fixtures and whatever funnels
 * the dev install has; up() after down() gives those back byte for byte.
 */
final class Update076Test extends TestCase
{
    /** A plain-JSON funnel and an entity-encoded one, as the store holds both. */
    const PLAIN   = '7600000000000076001';
    const ENCODED = '7600000000000076002';

    private ?\OWA\Module\Base\Update\Update076 $update = null;

    private string $definitionId = '';

    protected function setUp(): void
    {
        $this->update = new \OWA\Module\Base\Update\Update076();
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        $this->update->up();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DELETE FROM owa_custom_report WHERE id IN (?, ?)', [self::PLAIN, self::ENCODED]);

        if ($this->definitionId !== '') {
            \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition')->delete($this->definitionId);
        }
    }

    private function requireDatabase(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('renames owa_goal_event_definition and rewrites owa_custom_report');
        }

        $this->assertTrue($this->update->up(), 'the suite runs on the renamed tables');
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(76, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(76,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** The entities name what up() renames to, and namedBefore076() what down() renames back to. */
    public function testTheEntitiesNameBothSidesOfIt(): void
    {
        $definition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition');
        $condition  = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition_condition');

        $this->assertSame('owa_goal_event_definition', $definition->getTableName());
        $this->assertSame('owa_goal_event_definition_condition', $condition->getTableName());
        $this->assertContains('goal_event_definition_id', $condition->getColumns());

        $definition->namedBefore076();
        $condition->namedBefore076();

        $this->assertSame('owa_goal_event', $definition->getTableName());
        $this->assertSame('owa_goal_event_condition', $condition->getTableName());
        $this->assertContains('goal_event_id', $condition->getColumns());
        $this->assertNotContains('goal_event_definition_id', $condition->getColumns());
    }

    /** A key is a quoted name followed by a colon; the same text as a value is left alone. */
    public function testOnlyTheKeyIsRenamed(): void
    {
        $plain = '{"steps":[{"name":"goal_event_id","goal_event_id":"9","step_number":1},'
               . '{"name":"a \"goal_event_id\": in quotes","path":"/x","step_number":2}]}';

        $this->assertSame(
            '{"steps":[{"name":"goal_event_id","goal_event_definition_id":"9","step_number":1},'
          . '{"name":"a \"goal_event_id\": in quotes","path":"/x","step_number":2}]}',
            \OWA\Module\Base\Update\Update076::renameKey($plain, 'goal_event_id', 'goal_event_definition_id'));

        $encoded = htmlspecialchars($plain, ENT_QUOTES);

        $this->assertSame(
            htmlspecialchars(\OWA\Module\Base\Update\Update076::renameKey($plain, 'goal_event_id', 'goal_event_definition_id'), ENT_QUOTES),
            \OWA\Module\Base\Update\Update076::renameKey($encoded, 'goal_event_id', 'goal_event_definition_id'),
            'entity-encoded, as a definition is stored');

        $this->assertSame($plain, \OWA\Module\Base\Update\Update076::renameKey(
            \OWA\Module\Base\Update\Update076::renameKey($plain, 'goal_event_id', 'goal_event_definition_id'),
            'goal_event_definition_id', 'goal_event_id'), 'and back');
    }

    public function testDownPutsTheTablesAndTheColumnBackAndUpRenamesThemAgain(): void
    {
        $this->requireDatabase();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $id = $this->definition();

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'twice');

        $this->assertTrue($db->tableExists('owa_goal_event'));
        $this->assertTrue($db->tableExists('owa_goal_event_condition'));
        $this->assertFalse($db->tableExists('owa_goal_event_definition'));
        $this->assertFalse($db->tableExists('owa_goal_event_definition_condition'));
        $this->assertTrue($this->hasColumn('owa_goal_event_condition', 'goal_event_id'));
        $this->assertFalse($this->hasColumn('owa_goal_event_condition', 'goal_event_definition_id'));

        $this->assertSame('Renamed_76', $this->name('owa_goal_event', $id), 'the rows go with the table');
        $this->assertSame(1, $this->conditions('owa_goal_event_condition', 'goal_event_id', $id));

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'twice');

        $this->assertFalse($db->tableExists('owa_goal_event'));
        $this->assertFalse($db->tableExists('owa_goal_event_condition'));
        $this->assertTrue($this->hasColumn('owa_goal_event_definition_condition', 'goal_event_definition_id'));
        $this->assertFalse($this->hasColumn('owa_goal_event_definition_condition', 'goal_event_id'));
        $this->assertTrue($db->indexExists('owa_goal_event_definition_condition', 'goal_event_definition_id'),
            'the column keeps its index');

        $this->assertSame('Renamed_76', $this->name('owa_goal_event_definition', $id));
        $this->assertSame(1, $this->conditions('owa_goal_event_definition_condition', 'goal_event_definition_id', $id));

        // And the entity reads the definition and its conditions again.
        $definition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition');
        $definition->load($id);
        $this->assertCount(1, $definition->loadConditions());
    }

    /** A table under both names is refused before anything is renamed, not merged or dropped. */
    public function testATableUnderBothNamesIsRefused(): void
    {
        $this->requireDatabase();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertTrue((bool) $db->query('CREATE TABLE owa_goal_event LIKE owa_goal_event_definition'));

        try {
            $this->assertFalse($this->update->down());
            $this->assertTrue($db->tableExists('owa_goal_event_definition'), 'neither is renamed');
            $this->assertTrue($db->tableExists('owa_goal_event_definition_condition'), 'nor the other table');
            $this->assertTrue($this->hasColumn('owa_goal_event_definition_condition', 'goal_event_definition_id'),
                'nor its column');

            $this->assertFalse($this->update->up(), 'up() refuses it too');
        } finally {
            $db->query('DROP TABLE IF EXISTS owa_goal_event');
        }
    }

    public function testAFunnelStepsKeyIsRenamedBothWaysAndNothingElseInTheDefinition(): void
    {
        $this->requireDatabase();

        $definition = json_encode(['steps' => [
            ['name' => 'Pricing', 'path' => '/pricing', 'step_number' => 1],
            ['name' => 'goal_event_definition_id', 'goal_event_definition_id' => '9', 'step_number' => 2],
        ], 'constraints' => '']);

        $this->report(self::PLAIN, $definition);
        $this->report(self::ENCODED, htmlspecialchars($definition, ENT_QUOTES));

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'twice');

        $steps = json_decode($this->stored(self::PLAIN), true)['steps'];
        $this->assertSame('9', $steps[1]['goal_event_id'] ?? null);
        $this->assertArrayNotHasKey('goal_event_definition_id', $steps[1]);
        $this->assertSame('goal_event_definition_id', $steps[1]['name'], 'a step name is a value, not a key');
        $this->assertSame(['name' => 'Pricing', 'path' => '/pricing', 'step_number' => 1], $steps[0]);

        $steps = json_decode(html_entity_decode($this->stored(self::ENCODED), ENT_QUOTES), true)['steps'];
        $this->assertSame('9', $steps[1]['goal_event_id'] ?? null, 'entity-encoded, as a definition is stored');

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'twice');

        $this->assertSame($definition, $this->stored(self::PLAIN), 'up() gives back the bytes down() was given');
        $this->assertSame(htmlspecialchars($definition, ENT_QUOTES), $this->stored(self::ENCODED));
    }

    /* ---------------- helpers ---------------- */

    /** A definition with one condition, through the entities. @return string its id */
    private function definition(): string
    {
        $definition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition');
        $id         = $definition->generateId('goal_event:update076:' . uniqid('', true));

        $definition->setProperties(['id' => $id, 'property_id' => '7600000000000076099', 'name' => 'Renamed_76',
            'trigger_event_type' => 'page_view', 'is_active' => 1,
            'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);
        $this->assertTrue((bool) $definition->create());

        $this->definitionId = $id;

        $condition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition_condition');
        $condition->setProperties(['id' => $condition->generateId('goal_event_condition:' . $id . ':1'),
            'goal_event_definition_id' => $id, 'sort_order' => 1, 'condition_property' => 'page_path',
            'condition_operator' => 'exact', 'condition_value' => '/renamed',
            'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);
        $this->assertTrue((bool) $condition->create());

        return $id;
    }

    private function report(string $id, string $definition): void
    {
        $this->assertTrue((bool) \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_custom_report (id, name, report_type, visualization_type, definition) VALUES (?, ?, ?, ?, ?)',
            [$id, 'Update076 probe', 'visualization', 'funnel', $definition]));
    }

    private function stored(string $id): string
    {
        return (string) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT definition FROM owa_custom_report WHERE id = ?', [$id]))['definition'];
    }

    private function name(string $table, string $id): string
    {
        return (string) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            "SELECT name FROM $table WHERE id = ?", [$id]))['name'];
    }

    private function conditions(string $table, string $column, string $id): int
    {
        return count((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            "SELECT id FROM $table WHERE $column = ?", [$id]));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $table, $column));
    }
}
