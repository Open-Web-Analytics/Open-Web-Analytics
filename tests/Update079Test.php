<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update079;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update079: every saved funnel step becomes an event step, and down() gives
 * each funnel back the bytes it had.
 *
 * The database tests run up() on every saved funnel the suite's database holds,
 * these fixtures and whatever funnels the dev install has, and tearDown() runs
 * up() again, so the database ends converted, as an install at this schema is.
 */
final class Update079Test extends TestCase
{
    const PLAIN   = '7900000000000079001';
    const ENCODED = '7900000000000079002';
    const CUSTOM  = '7900000000000079003';

    const GOAL        = '7900000000000079101';
    const CUSTOM_GOAL = '7900000000000079102';
    const GONE        = '7900000000000079199';

    private Update079 $update;

    protected function setUp(): void
    {
        $this->update = new Update079();
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        $this->update->up();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DELETE FROM owa_custom_report WHERE id IN (?, ?, ?)', [self::PLAIN, self::ENCODED, self::CUSTOM]);
        $db->query('DELETE FROM owa_goal_event_definition_condition WHERE goal_event_definition_id IN (?, ?)',
            [self::GOAL, self::CUSTOM_GOAL]);
        $db->query('DELETE FROM owa_goal_event_definition WHERE id IN (?, ?)', [self::GOAL, self::CUSTOM_GOAL]);

        $before = (array) \OWA\Core\CoreAPI::getSetting('base', Update079::BEFORE);
        unset($before[self::PLAIN], $before[self::ENCODED], $before[self::CUSTOM]);
        \OWA\Core\CoreAPI::persistSetting('base', Update079::BEFORE, $before);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(79, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(79,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** A path step is a page_view on its path; a typed ? is split into page_path and page_query. */
    public function testAPathStepBecomesAPageViewStep(): void
    {
        $out = Update079::convert(['constraints' => 'pagePath=@/', 'steps' => [
            ['name' => 'Store', 'path' => '/store/', 'step_number' => 1],
            ['name' => 'Two', 'path' => '/checkout?step=2', 'step_number' => 2],
            ['name' => 'Bare', 'path' => '/checkout?', 'step_number' => 3],
        ]], []);

        $this->assertSame(['constraints' => 'pagePath=@/', 'steps' => [
            ['name' => 'Store', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/store/'],
            ], 'step_number' => 1],
            ['name' => 'Two', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/checkout'],
                ['property' => 'page_query', 'operator' => 'exact', 'value' => 'step=2'],
            ], 'step_number' => 2],
            ['name' => 'Bare', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/checkout'],
            ], 'step_number' => 3],
        ]], $out['definition'], 'the typed path is kept as typed: the funnel canonicalises it when it counts');

        $this->assertSame([0, 1, 2], array_keys($out['steps']));
        $this->assertSame(['name' => 'Store', 'path' => '/store/', 'step_number' => 1], $out['steps'][0]['was']);
        $this->assertSame([], $out['left']);
    }

    /** A goal event step carries a copy of its definition's trigger, combinator and conditions, and keeps its name. */
    public function testAGoalEventStepBecomesAnEventStep(): void
    {
        $conditions = [
            ['property' => 'revenue', 'operator' => 'gt', 'value' => '50'],
            ['property' => 'page_path', 'operator' => 'exact', 'value' => '/pricing'],
        ];

        $out = Update079::convert(['steps' => [
            ['name' => 'Bought', 'goal_event_definition_id' => '9', 'step_number' => 1],
        ]], ['9' => ['trigger' => 'purchase', 'match' => 'any', 'conditions' => $conditions]]);

        $this->assertSame(['name' => 'Bought', 'kind' => 'event', 'event' => 'purchase', 'match' => 'any',
            'conditions' => $conditions, 'step_number' => 1], $out['definition']['steps'][0]);
    }

    /** A goal on a site's own event is a step on that event's name, conditions or none. */
    public function testACustomTriggerBecomesAStepOnThatEvent(): void
    {
        $out = Update079::convert(['steps' => [
            ['name' => 'Signed up', 'goal_event_definition_id' => '9', 'step_number' => 1],
        ]], ['9' => ['trigger' => 'newsletter_signup', 'match' => 'all', 'conditions' => []]]);

        $this->assertSame('newsletter_signup', $out['definition']['steps'][0]['event']);
        $this->assertSame([], $out['definition']['steps'][0]['conditions']);
    }

    /**
     * A goal event step whose definition is gone, or matched nothing, is left
     * as it is and named: as an event step with no conditions it would match
     * every event of its name.
     */
    public function testAStepWhoseDefinitionIsGoneOrMatchesNothingIsLeft(): void
    {
        $steps = [
            ['name' => 'Gone', 'goal_event_definition_id' => '1', 'step_number' => 1],
            ['name' => 'Nothing', 'goal_event_definition_id' => '2', 'step_number' => 2],
            ['name' => 'No trigger', 'goal_event_definition_id' => '3', 'step_number' => 3],
            ['name' => 'Done', 'kind' => 'event', 'event' => 'click', 'match' => 'all', 'conditions' => [], 'step_number' => 4],
        ];

        $out = Update079::convert(['steps' => $steps], [
            '2' => ['trigger' => 'page_view', 'match' => 'all', 'conditions' => []],
            '3' => ['trigger' => '', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/a']]],
        ]);

        $this->assertSame(['steps' => $steps], $out['definition']);
        $this->assertSame([], $out['steps']);
        $this->assertSame([0, 1, 2], array_keys($out['left']));
        $this->assertStringContainsString('no longer exists', $out['left'][0]);
        $this->assertStringContainsString('matches nothing', $out['left'][1]);
    }

    private function requireDatabase(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('rewrites owa_custom_report');
        }
    }

    private function report(string $id, string $definition): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_custom_report (id, name, user_id, report_type, visualization_type, definition,'
            . ' is_shared, creation_timestamp, last_updated_timestamp) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [$id, 'Update079 fixture ' . $id, 'nobody', 'visualization', 'funnel', $definition, time(), time()]);
    }

    private function goal(string $id, string $trigger, array $conditions): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('INSERT INTO owa_goal_event_definition (id, property_id, name, trigger_event_type, condition_match,'
            . ' is_active) VALUES (?, ?, ?, ?, ?, 1)', [$id, '7900000000000079900', 'Fixture_' . $id, $trigger, 'all']);

        foreach ($conditions as $i => $c) {
            $db->query('INSERT INTO owa_goal_event_definition_condition (id, goal_event_definition_id, sort_order,'
                . ' condition_property, condition_operator, condition_value) VALUES (?, ?, ?, ?, ?, ?)',
                [(string) ((int) $id + 1000 + $i), $id, $i, $c[0], $c[1], $c[2]]);
        }
    }

    private function stored(string $id): string
    {
        return (string) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT definition FROM owa_custom_report WHERE id = ?', [$id])['definition'];
    }

    private function steps(string $id): array
    {
        return json_decode(html_entity_decode($this->stored($id), ENT_QUOTES), true)['steps'];
    }

    /**
     * up, up again, down, down again: each definition ends with the bytes it
     * started with, plain JSON or entity-encoded, and the record is gone.
     */
    public function testUpAndDownRunTwiceAndDownGivesBackTheBytes(): void
    {
        $this->requireDatabase();

        $this->goal(self::GOAL, 'page_view', [['page_path', 'exact', '/thanks']]);
        $this->goal(self::CUSTOM_GOAL, 'newsletter_signup', []);

        $plain = json_encode(['steps' => [
            ['name' => 'Home', 'path' => '/', 'step_number' => 1],
            ['name' => 'Thanks', 'goal_event_definition_id' => self::GOAL, 'step_number' => 2],
        ]]);

        // As the entity stores one: quotes as &quot;. A step naming a definition that is gone.
        $encoded = htmlspecialchars(json_encode(['constraints' => 'pagePath=@/blog/', 'steps' => [
            ['name' => 'Blog', 'path' => '/blog/?page=2', 'step_number' => 1],
            ['name' => 'Gone', 'goal_event_definition_id' => self::GONE, 'step_number' => 2],
        ]]), ENT_QUOTES);

        $custom = json_encode(['steps' => [
            ['name' => 'Signed up', 'goal_event_definition_id' => self::CUSTOM_GOAL, 'step_number' => 1],
        ]]);

        $this->report(self::PLAIN, $plain);
        $this->report(self::ENCODED, $encoded);
        $this->report(self::CUSTOM, $custom);

        $this->assertTrue($this->update->up());

        $this->assertSame([
            ['name' => 'Home', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/'],
            ], 'step_number' => 1],
            ['name' => 'Thanks', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all', 'conditions' => [
                ['property' => 'page_path', 'operator' => 'exact', 'value' => '/thanks'],
            ], 'step_number' => 2],
        ], $this->steps(self::PLAIN));

        $encodedSteps = $this->steps(self::ENCODED);
        $this->assertSame('event', $encodedSteps[0]['kind']);
        $this->assertSame([
            ['property' => 'page_path', 'operator' => 'exact', 'value' => '/blog/'],
            ['property' => 'page_query', 'operator' => 'exact', 'value' => 'page=2'],
        ], $encodedSteps[0]['conditions']);
        $this->assertSame(['name' => 'Gone', 'goal_event_definition_id' => self::GONE, 'step_number' => 2],
            $encodedSteps[1], 'a step naming a definition that is gone is left as it is');

        $this->assertSame('newsletter_signup', $this->steps(self::CUSTOM)[0]['event']);

        $after = [$this->stored(self::PLAIN), $this->stored(self::ENCODED), $this->stored(self::CUSTOM)];

        $this->assertTrue($this->update->up(), 'twice');
        $this->assertSame($after, [$this->stored(self::PLAIN), $this->stored(self::ENCODED), $this->stored(self::CUSTOM)]);

        $record = (array) \OWA\Core\CoreAPI::getSetting('base', Update079::BEFORE);
        $this->assertSame($plain, $record[self::PLAIN]['was']);
        $this->assertSame($encoded, $record[self::ENCODED]['was']);

        $this->assertTrue($this->update->down());
        $this->assertSame($plain, $this->stored(self::PLAIN), 'byte for byte');
        $this->assertSame($encoded, $this->stored(self::ENCODED), 'byte for byte, entity-encoded');
        $this->assertSame($custom, $this->stored(self::CUSTOM));
        $this->assertSame([], (array) \OWA\Core\CoreAPI::getSetting('base', Update079::BEFORE), 'the record goes');

        $this->assertTrue($this->update->down(), 'twice');
        $this->assertSame($plain, $this->stored(self::PLAIN));
        $this->assertSame($encoded, $this->stored(self::ENCODED));
    }

    /** A funnel edited after up() gets its converted steps back, and keeps the edit. */
    public function testDownKeepsAnEditMadeSince(): void
    {
        $this->requireDatabase();

        $plain = json_encode(['steps' => [
            ['name' => 'Home', 'path' => '/', 'step_number' => 1],
            ['name' => 'Docs', 'path' => '/docs', 'step_number' => 2],
        ]]);

        $this->report(self::PLAIN, $plain);

        $this->assertTrue($this->update->up());

        $edited = json_decode($this->stored(self::PLAIN), true);
        $edited['constraints'] = 'pagePath=@/';
        $edited['steps'][1]['conditions'][0]['value'] = '/guide';

        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_custom_report SET definition = ? WHERE id = ?',
            [json_encode($edited), self::PLAIN]);

        $this->assertTrue($this->update->down());

        $back = json_decode($this->stored(self::PLAIN), true);

        $this->assertSame('pagePath=@/', $back['constraints'], 'the edit is kept');
        $this->assertSame(['name' => 'Home', 'path' => '/', 'step_number' => 1], $back['steps'][0],
            'the step still as converted is put back');
        $this->assertSame('/guide', $back['steps'][1]['conditions'][0]['value'], 'the step edited since is not');
    }
}
