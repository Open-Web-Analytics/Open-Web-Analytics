<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * attributed_* through a real build (PLAN 2.29).
 *
 * A session's attributed source is its own if it arrived with tags or a
 * referrer, else the prior touch stamped on its first event when that touch is
 * within the Property's lookback, else (direct). Each case is one session of
 * its own visitor in one partition, built by the real statement.
 */
final class AttributedSourceBuildTest extends TestCase
{
    const SITE     = 'owa-attributed-test-site';
    const PROPERTY = 7782000000000001;

    const OWN_TAGS      = 7782100000000001;
    const INHERITS      = 7782100000000002;
    const TOO_OLD       = 7782100000000003;
    const NO_TOUCH      = 7782100000000004;
    const INHERITS_HOST = 7782100000000005;
    const OWN_REFERRER  = 7782100000000006;

    /** @var int */
    private $yyyymmdd;

    /** @var int microseconds, two hours ago: every session reads closed */
    private $t0;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::drop();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id' => self::PROPERTY, 'name' => 'Attributed fixture', 'domain' => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time(),
        ]);
        $property->create();

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id' => self::PROPERTY * 10, 'site_id' => self::SITE, 'property_id' => self::PROPERTY,
            'name' => 'Attributed fixture profile', 'domain' => 'example.test',
        ]);
        $site->create();

        \OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY);
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::drop();
        }
    }

    private static function drop(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory('base.site')->getTableName(), self::SITE));
        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base', 'attribution_lookback_days');

        $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('builds a cube');
        }

        $this->yyyymmdd = (int) date('Ymd');
        $this->t0       = (time() - 7200) * 1000000;

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base', 'attribution_lookback_days');

        $days = 86400 * 1000000;
        $newsletter = ['prior_touch_source' => 'newsletter', 'prior_touch_medium' => 'email',
            'prior_touch_campaign' => 'spring'];

        // Arrived tagged, with an older touch stamped too: its own tags win.
        $this->landing(self::OWN_TAGS, [
            'tagged_source' => 'partner', 'tagged_medium' => 'cpc', 'tagged_campaign' => 'autumn',
            'prior_touch_ts' => $this->t0 - 10 * $days] + $newsletter);

        // Arrived from a search engine: a referrer is a touch of its own.
        $this->landing(self::OWN_REFERRER, ['referer_host' => 'www.google.com',
            'prior_touch_ts' => $this->t0 - 10 * $days] + $newsletter);

        // Arrived direct, newsletter 30 days earlier: inherits it.
        $this->landing(self::INHERITS, ['prior_touch_ts' => $this->t0 - 30 * $days] + $newsletter);

        // Arrived direct, an untagged search visit 5 days earlier: inherits organic.
        $this->landing(self::INHERITS_HOST, ['prior_touch_referer_host' => 'www.google.com',
            'prior_touch_ts' => $this->t0 - 5 * $days]);

        // Arrived direct, newsletter 120 days earlier: outside the default 90.
        $this->landing(self::TOO_OLD, ['prior_touch_ts' => $this->t0 - 120 * $days] + $newsletter);

        // Arrived direct, nothing before it.
        $this->landing(self::NO_TOUCH, []);
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
            $this->rebuild();
        }
    }

    /** One session: its landing page view and a second page view without the stamp. */
    private function landing(int $visitor, array $row): void
    {
        $session = $visitor + 500;

        foreach ([[$this->t0, $row, 1], [$this->t0 + 60 * 1000000, [], 2]] as [$ts, $extra, $seq]) {

            $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
            $entity->setProperties($extra + [
                'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, 'page_view'),
                'event_type'    => 'page_view',
                'site_id'       => self::SITE,
                'visitor_id'    => $visitor,
                'session_id'    => $session,
                'ts'            => $ts,
                'event_seq'     => $seq,
                'yyyymmdd'      => $this->yyyymmdd,
                'prior_sessions' => 1,
                'page_location' => 'https://example.test/p',
                'page_path'     => '/p',
                'is_goal_event' => 0,
            ]);

            $this->assertTrue($entity->create(), 'seeding raw');
        }
    }

    private function rebuild(): void
    {
        $builder = new \OWA\Module\Base\Classes\Cube\Builder(self::PROPERTY);

        foreach ($builder->partitions($this->yyyymmdd, $this->yyyymmdd) as $span) {
            $result = $builder->rebuild($span);
            $this->assertTrue($result['ok'], 'rebuild failed: ' . $result['error']);
        }
    }

    /** @return array[] visitor => [source, medium, campaign, channel] for both page views */
    private function attributed(): array
    {
        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            'SELECT visitor_id, event_seq, source, attributed_source, attributed_medium, attributed_campaign,'
          . ' attributed_channel FROM %s WHERE yyyymmdd = %d ORDER BY visitor_id, event_seq',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY), $this->yyyymmdd));

        $out = [];

        foreach ((array) $rows as $row) {
            $out[(int) $row['visitor_id']][] = [$row['attributed_source'], $row['attributed_medium'],
                $row['attributed_campaign'], $row['attributed_channel']];
        }

        return $out;
    }

    public function testEachSessionIsAttributedByItsOwnArrivalOrItsPriorTouch(): void
    {
        $this->rebuild();
        $got = $this->attributed();

        $expected = [
            self::OWN_TAGS      => ['partner', 'cpc', 'autumn', 'Paid Other'],
            self::OWN_REFERRER  => ['www.google.com', 'organic', '(organic)', 'Organic Search'],
            self::INHERITS      => ['newsletter', 'email', 'spring', 'Email'],
            self::INHERITS_HOST => ['www.google.com', 'organic', '(organic)', 'Organic Search'],
            self::TOO_OLD       => ['(direct)', '(none)', '(direct)', 'Direct'],
            self::NO_TOUCH      => ['(direct)', '(none)', '(direct)', 'Direct'],
        ];

        foreach ($expected as $visitor => $values) {
            $this->assertSame([$values, $values], $got[$visitor] ?? null,
                "visitor $visitor, on both page views of the session");
        }
    }

    /** The lookback is the Property's setting, applied at build: 150 days reaches the 120-day touch. */
    public function testAPropertysLookbackReachesHistoryOnRebuild(): void
    {
        \OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', 'attribution_lookback_days', 150);
        $this->assertSame(150, \OWA\Module\Base\Classes\Cube\Builder::lookbackDays((string) self::PROPERTY));

        $this->rebuild();
        $this->assertSame('newsletter', $this->attributed()[self::TOO_OLD][0][0]);

        // And shorter: 20 days loses the 30-day newsletter, keeps the 5-day search.
        \OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', 'attribution_lookback_days', 20);
        $this->rebuild();

        $got = $this->attributed();
        $this->assertSame('(direct)', $got[self::INHERITS][0][0]);
        $this->assertSame('www.google.com', $got[self::INHERITS_HOST][0][0]);
    }

    /** Without a Property override, the install's setting applies, defaulting to 90. */
    public function testTheLookbackInheritsTheInstall(): void
    {
        $this->assertSame((int) \OWA\Core\CoreAPI::getSetting('base', 'attribution_lookback_days'),
            \OWA\Module\Base\Classes\Cube\Builder::lookbackDays((string) self::PROPERTY));
        $this->assertSame(90, \OWA\Module\Base\Classes\Cube\Context::DEFAULT_LOOKBACK_DAYS);
    }
}
