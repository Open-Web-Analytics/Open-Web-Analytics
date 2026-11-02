<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update075: goal events get an event name, and the events flagged before it
 * get conversion rows of their own.
 *
 * down() is exercised, and up() runs again after it, so the database the suite
 * runs against ends where it started. Both walk every flagged row in raw, which
 * on the suite's database is these fixtures and whatever the dev install has
 * converted since; up() after down() restores those too.
 */
final class Update075Test extends TestCase
{
    const VISITOR = 7775000000075001;

    private string $siteId = '';

    private string $propertyId = '';

    private array $goals = [];

    private \OWA\Module\Base\Update\Update075 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes owa_event_raw and owa_goal_event');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ((array) $db->get_results('SELECT site_id, property_id FROM owa_site WHERE property_id IS NOT NULL') as $row) {
            if (!empty($row['property_id'])) {
                $this->siteId     = (string) $row['site_id'];
                $this->propertyId = (string) $row['property_id'];
                break;
            }
        }

        if (!$this->siteId) {
            $this->markTestSkipped('Needs a Profile with a Property.');
        }

        $this->update = new \OWA\Module\Base\Update\Update075();
        $this->assertTrue($this->update->up(), 'the column has to be there before a goal event can carry it');
    }

    protected function tearDown(): void
    {
        if (!$this->siteId) {
            return;
        }

        $this->update->up();

        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_event_raw WHERE visitor_id = ?', [self::VISITOR]);

        foreach ($this->goals as $id) {
            \OWA\Core\CoreAPI::entityFactory('base.goal_event')->delete($id);
        }

        \OWA\Module\Base\Classes\GoalMarking::forget();
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(75, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(75,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    public function testAGoalEventWithoutAnEventNameGetsOneThatIsUsableAndUnique(): void
    {
        $first  = $this->goal('Bookshop.com-Buy', [['page_path', 'exact', '/a']], null);
        $second = $this->goal('Bookshop.com Buy', [['page_path', 'exact', '/b']], null);
        $click  = $this->goal('click', [['page_path', 'exact', '/c']], null);

        $this->assertTrue($this->update->up());

        $names = [];

        foreach ([$first, $second, $click] as $id) {
            $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT event_name FROM owa_goal_event WHERE id = ?', [$id]);
            $names[] = $row['event_name'];
            $this->assertSame('', \OWA\Module\Base\Entity\GoalEvent::eventNameProblem($row['event_name']), $row['event_name']);
        }

        // The two Bookshop ones are named in id order, which is not creation order.
        sort($names);
        $this->assertSame(['Bookshop_com_Buy', 'Bookshop_com_Buy_2', 'click_2'], $names,
            'a second goal event of the Property cannot share the first one\'s rows, and click is an event OWA collects');
    }

    public function testAFlaggedEventGetsItsConversionRowAndDownPutsTheFlagBack(): void
    {
        $this->goal('Contact page', [['page_path', 'exact', '/contact']], 'Contact_page');

        $met    = $this->event(1, '/contact');
        $missed = $this->event(2, '/pricing');

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'twice');

        $this->assertSame(0, $this->flag($met), 'the event that met it is an ordinary event now');
        $this->assertSame(1, $this->flag($missed), 'meets no goal event, so it keeps its flag');

        $conversions = $this->conversions();
        $this->assertCount(1, $conversions, 'and a second run adds none');
        $this->assertSame('Contact_page', $conversions[0]['event_type']);
        $this->assertSame('/contact', $conversions[0]['page_path']);
        $this->assertSame((string) $met['ts'], (string) $conversions[0]['ts']);

        $this->assertTrue($this->update->down());

        $this->assertSame(1, $this->flag($met));
        $this->assertSame(1, $this->flag($missed));
        $this->assertSame([], $this->conversions());
        $this->assertEmpty((array) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW COLUMNS FROM owa_goal_event LIKE 'event_name'"));
    }

    /** A flagged page view on this Profile, today. @return array its key */
    private function event(int $n, string $path): array
    {
        $ts  = (int) round(microtime(true) * 1000000) - $n * 1000000;
        $row = [
            'id'            => \OWA\Module\Base\Classes\V2Event::id($this->siteId, self::VISITOR, self::VISITOR + $n, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => $this->siteId,
            'visitor_id'    => self::VISITOR,
            'session_id'    => self::VISITOR + $n,
            'ts'            => $ts,
            'yyyymmdd'      => (int) date('Ymd', intdiv($ts, 1000000)),
            'page_location' => 'https://example.test' . $path,
            'page_path'     => $path,
            'is_goal_event' => 1,
        ];

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties($row);
        $this->assertTrue((bool) $entity->create(), 'seeding owa_event_raw');

        return $row;
    }

    private function flag(array $row): int
    {
        return (int) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT is_goal_event FROM owa_event_raw WHERE id = ? AND yyyymmdd = ?', [$row['id'], $row['yyyymmdd']]))['is_goal_event'];
    }

    private function conversions(): array
    {
        return array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            "SELECT * FROM owa_event_raw WHERE visitor_id = ? AND event_type <> 'page_view'", [self::VISITOR]));
    }

    private function goal(string $name, array $conditions, ?string $event_name): string
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.goal_event');
        $id     = $entity->generateId('goal_event:update075:' . uniqid('', true));

        $entity->setProperties(['id' => $id, 'property_id' => $this->propertyId, 'name' => $name,
            'trigger_event_type' => 'page_view', 'is_active' => 1,
            'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);

        if ($event_name !== null) {
            $entity->set('event_name', $event_name);
        }

        $this->assertTrue((bool) $entity->create());
        $this->goals[] = $id;

        foreach ($conditions as $n => $c) {
            $row = \OWA\Core\CoreAPI::entityFactory('base.goal_event_condition');
            $row->setProperties(['id' => $row->generateId('goal_event_condition:' . $id . ':' . $n),
                'goal_event_id' => $id, 'sort_order' => $n + 1, 'condition_property' => $c[0],
                'condition_operator' => $c[1], 'condition_value' => $c[2],
                'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);
            $this->assertTrue((bool) $row->create());
        }

        return $id;
    }
}
