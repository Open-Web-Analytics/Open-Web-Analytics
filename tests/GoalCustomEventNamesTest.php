<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * The custom events the goal builder offers as triggers: what a Property's
 * sites sent lately, read from its cube, most sent first, and nothing of OWA's
 * own -- not its events and not a goal event's conversions.
 */
final class GoalCustomEventNamesTest extends TestCase
{
    const SITE     = 'owa-goal-custom-site';
    const PROPERTY = 7776000000000001;

    const VISITOR = 7776100000000001;
    const SESSION = 8886100000000001;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Custom event goal fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Custom event goal fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), $db->prepare(self::SITE)));
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads a real cube.');
        }
    }

    /** One raw row of this event type, a second apart from the last. */
    private function seed(string $type, int $n, int $is_goal = 0): void
    {
        $ts = (time() - 3600 + $n) * 1000000;

        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $raw->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, self::SESSION, $ts, $type),
            'event_type'    => $type,
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR,
            'session_id'    => self::SESSION,
            'ts'            => $ts,
            'yyyymmdd'      => (int) date('Ymd'),
            'is_goal_event' => $is_goal,
            'page_location' => 'https://example.test/p',
            'page_path'     => '/p',
            'page_title'    => 'P',
        ]);

        $this->assertTrue($raw->create(), "seeding a $type row");
    }

    public function testTheBuilderOffersTheSitesOwnEventsMostSentFirst(): void
    {
        $n = 0;

        foreach (['video_play', 'newsletter_signup', 'newsletter_signup', 'page_view', 'page_view',
                  'click', 'exception'] as $type) {
            $this->seed($type, ++$n);
        }

        // A goal event's conversion is stored under its own name.
        $this->seed('Signed_up', ++$n, 1);

        $builder = new \OWA\Module\Base\Classes\Cube\Builder(self::PROPERTY);

        foreach ($builder->partitions((int) date('Ymd'), (int) date('Ymd')) as $span) {
            $this->assertTrue($builder->rebuild($span)['ok'], 'rebuilding ' . $span['name']);
        }

        $this->assertSame(['newsletter_signup', 'video_play'],
            \OWA\Module\Base\Controller\GoalEventEdit::customEventNames((string) self::PROPERTY));

        $this->assertSame([], \OWA\Module\Base\Controller\GoalEventEdit::customEventNames('not-a-property'));
    }
}
