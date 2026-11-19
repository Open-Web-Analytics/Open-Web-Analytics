<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Columns;
use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * A build's session and page windows read the days around the span only for
 * the sessions that have a row in it (Builder::spanSessions()), and
 * isCurrent() asks raw one question rather than two.
 *
 * The SQL tests need no database. The equivalence test builds a span twice
 * against the configured database, once with the restriction and once
 * without it, and compares every column of every row.
 */
final class CubeWindowSpanSessionsTest extends TestCase
{
    const PROPERTY = 7787000000000001;
    const SITE     = 'cube-window-fixture';

    /** A profile of no Property in the fixture, sharing the fixture's ids. */
    const FOREIGN_SITE = 'cube-window-foreign';

    const VISITOR       = 8887000000000001;
    const OTHER_VISITOR = 8887000000000002;

    const SESSION_IN     = 8887100000000001;
    const SESSION_OUT    = 8887100000000002;
    const SESSION_INSIDE = 8887100000000003;
    const SESSION_BEFORE = 8887100000000004;
    const SESSION_AFTER  = 8887100000000005;

    private static bool $seeded = false;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Cube window fixture',
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
            'name'        => 'Cube window fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        self::$seeded = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available() || !self::$seeded) {
            return;
        }

        self::dropFixture();
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::SITE, self::FOREIGN_SITE] as $site_id) {
            foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), $site_id));
            }
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    /**
     * A Builder that touches no database: its reads go to a stub that records
     * them and answers from a list.
     *
     * @param list<array<string, mixed>|null> $answers get_row()'s answers, in order
     */
    private function offline(array $answers = []): array
    {
        $db = new class($answers) {
            /** @var list<string> */
            public array $queries = [];

            public function __construct(private array $answers)
            {
            }

            public function prepare($value): string
            {
                return addslashes((string) $value);
            }

            public function get_row($sql)
            {
                $this->queries[] = $sql;

                return array_shift($this->answers);
            }
        };

        $class   = new ReflectionClass(Builder::class);
        $builder = $class->newInstanceWithoutConstructor();

        $class->getProperty('db')->setValue($builder, $db);
        $class->getProperty('site_ids')->setValue($builder, ['site-a']);
        $class->getProperty('tables')->setValue($builder, ['raw' => 'owa_event_raw', 'target' => 'owa_event_1']);
        $class->getProperty('columns')->setValue($builder, new Columns());

        return [$builder, $db];
    }

    private function invoke(Builder $builder, string $method, array $args): string
    {
        return (string) (new ReflectionMethod(Builder::class, $method))->invokeArgs($builder, $args);
    }

    /** The span's sessions, of the span's own Property. */
    private function semiJoin(string $alias, int $start, int $end): string
    {
        return sprintf(" AND (%1\$s.site_id, %1\$s.visitor_id, %1\$s.session_id) IN (SELECT ss.site_id, ss.visitor_id,"
          . " ss.session_id FROM owa_event_raw ss WHERE ss.yyyymmdd >= %2\$d AND ss.yyyymmdd < %3\$d"
          . " AND ss.site_id IN ('site-a'))", $alias, $start, $end);
    }

    public function testTheSessionWindowReadsTheDaysAroundTheSpanForItsSessionsOnly(): void
    {
        [$builder] = $this->offline();

        $sql = $this->invoke($builder, 'windowedRaw', [20261109, 20261112, 20261110, 20261111]);

        $this->assertStringContainsString(
            "FROM owa_event_raw w WHERE w.yyyymmdd >= 20261109 AND w.yyyymmdd < 20261112 AND w.site_id IN ('site-a')"
          . $this->semiJoin('w', 20261110, 20261111) . ' WINDOW session_w AS',
            $sql, 'the day before and the day after, restricted to the sessions of 20261110');
    }

    public function testThePageWindowReadsTheDaysAroundTheSpanForItsSessionsOnly(): void
    {
        [$builder] = $this->offline();

        $sql = $this->invoke($builder, 'pagesRaw', [20261109, 20261112, 20261110, 20261111]);

        $this->assertStringContainsString(
            "WHERE p.event_type = 'page_view' AND p.yyyymmdd >= 20261109 AND p.yyyymmdd < 20261112"
          . " AND p.site_id IN ('site-a')" . $this->semiJoin('p', 20261110, 20261111) . ' WINDOW pages_w AS',
            $sql);
    }

    /** A Property with no profiles reads nothing, in the subquery as in the rest. */
    public function testTheSessionsOfAPropertyWithNoProfilesAreNone(): void
    {
        [$builder] = $this->offline();

        (new ReflectionProperty(Builder::class, 'site_ids'))->setValue($builder, []);

        $this->assertStringContainsString('AND ss.yyyymmdd < 20261111 AND 1 = 0)',
            $this->invoke($builder, 'spanSessions', ['w', 20261110, 20261111]));
    }

    /**
     * One question after builtAt(), not two MAX scans: EXISTS tests against
     * the build time less a session length, the continuation restricted to
     * the span's sessions.
     */
    public function testIsCurrentAsksRawOnce(): void
    {
        $built_at = 1795000000000000;
        $length   = ((int) \OWA\Core\CoreAPI::getSetting('base', 'session_length') ?: 1800) * 1000000;
        $span     = ['name' => 'p20261110', 'start' => 20261110, 'less_than' => 20261111];

        [$builder, $db] = $this->offline([['built_at' => $built_at], ['current' => '1']]);

        $this->assertTrue($builder->isCurrent($span));
        $this->assertCount(2, $db->queries, 'builtAt(), then one statement');

        $sql = $db->queries[1];

        $this->assertStringNotContainsString('MAX(', $sql);
        $this->assertStringContainsString(sprintf(
            "WHEN EXISTS (SELECT 1 FROM owa_event_raw r WHERE r.yyyymmdd >= 20261110 AND r.yyyymmdd < 20261111"
          . " AND r.site_id IN ('site-a') AND r.created_at > %d) THEN 0", $built_at - $length), $sql);
        $this->assertStringContainsString("AND r.created_at IS NOT NULL) THEN 0", $sql);
        $this->assertStringContainsString(sprintf(
            "WHEN EXISTS (SELECT 1 FROM owa_event_raw n WHERE n.yyyymmdd >= 20261111 AND n.yyyymmdd < 20261112"
          . " AND n.site_id IN ('site-a') AND n.created_at > %d%s) THEN 0", $built_at - $length,
            $this->semiJoin('n', 20261110, 20261111)), $sql);

        [$builder] = $this->offline([['built_at' => $built_at], ['current' => '0']]);
        $this->assertFalse($builder->isCurrent($span));

        [$builder, $db] = $this->offline([null]);
        $this->assertFalse($builder->isCurrent($span), 'never built');
        $this->assertCount(1, $db->queries);
    }

    /**
     * THE RESTRICTION CHANGES NO VALUE. One span, with a session that began
     * the day before it, one that goes on into the day after it, one inside
     * it, and around it: sessions wholly on the neighbouring days, another
     * visitor's session under the same session id the day before, and another
     * profile's rows under the fixture's own visitor and session ids. Built
     * once without the restriction and once with it; every column of every
     * row but built_at must agree.
     *
     * Needs a scratch-DB run: it writes raw and builds a cube.
     */
    public function testTheBuildIsTheSameAsReadingTheDaysAroundInFull(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this builds a cube.');
        }

        $day     = (int) date('Ymd', strtotime('-3 days'));
        $builder = new Builder(self::PROPERTY);
        $span    = $builder->partitions($day, $day)[0];

        $start = (int) $span['start'];
        $end   = (int) $span['less_than'];
        $prev  = (int) date('Ymd', strtotime($start . ' -1 day'));
        $last  = (int) date('Ymd', strtotime($end . ' -1 day'));

        $at = static fn (int $yyyymmdd, string $time): int =>
            strtotime(date('Y-m-d', strtotime((string) $yyyymmdd)) . ' ' . $time) * 1000000;

        // Began the day before: its landing page and its source are there.
        $this->seed($prev, self::SESSION_IN, $at($prev, '23:50:00'), 1, '/in-landing',
            ['tagged_source' => 'newsletter', 'tagged_medium' => 'email', 'engagement_msec' => 4000]);
        $this->seed($start, self::SESSION_IN, $at($start, '00:05:00'), 2, '/in-second', ['engagement_msec' => 9000]);
        $this->seed($start, self::SESSION_IN, $at($start, '00:06:00'), 3, '/in-second', [], 'click');

        // Goes on into the day after: its next page and its exit are there.
        $this->seed($last, self::SESSION_OUT, $at($last, '23:40:00'), 1, '/out-landing');
        $this->seed($last, self::SESSION_OUT, $at($last, '23:55:00'), 2, '/out-second');
        $this->seed($end, self::SESSION_OUT, $at($end, '00:10:00'), 3, '/out-exit', ['engagement_msec' => 12000]);

        $this->seed($start, self::SESSION_INSIDE, $at($start, '12:00:00'), 1, '/inside', ['referer_host' => 'www.example.org']);
        $this->seed($start, self::SESSION_INSIDE, $at($start, '12:01:00'), 2, '/inside-two');

        // Around the span, and in none of its sessions.
        $this->seed($prev, self::SESSION_BEFORE, $at($prev, '10:00:00'), 1, '/before');
        $this->seed($end, self::SESSION_AFTER, $at($end, '10:00:00'), 1, '/after');
        $this->seed($prev, self::SESSION_IN, $at($prev, '23:30:00'), 1, '/other-visitor', [], 'page_view',
            self::OTHER_VISITOR);
        $this->seed($prev, self::SESSION_IN, $at($prev, '23:40:00'), 1, '/foreign', [], 'page_view',
            self::VISITOR, self::FOREIGN_SITE);
        $this->seed($end, self::SESSION_OUT, $at($end, '00:20:00'), 4, '/foreign', [], 'page_view',
            self::VISITOR, self::FOREIGN_SITE);

        $unrestricted = new class(self::PROPERTY) extends Builder {
            protected function spanSessions($alias, $start, $end)
            {
                return '';
            }
        };

        $before = $unrestricted->rebuild($span);
        $this->assertTrue($before['ok'], 'the unrestricted build: ' . $before['error']);
        $this->assertStringNotContainsString('ss.session_id', $before['sql']);
        $expected = $this->cubeRows($span);

        $after = (new Builder(self::PROPERTY))->rebuild($span);
        $this->assertTrue($after['ok'], 'the restricted build: ' . $after['error']);
        $this->assertSame(2, substr_count($after['sql'], 'IN (SELECT ss.site_id, ss.visitor_id, ss.session_id'),
            'both windows read the span\'s sessions only');
        $actual = $this->cubeRows($span);

        $this->assertCount(6, $actual, 'the span\'s rows, and only those');
        $this->assertSame($expected, $actual);

        // And the neighbouring days were read: these come from them.
        $in  = $actual[$this->idOf(self::SESSION_IN, $at($start, '00:05:00'))];
        $out = $actual[$this->idOf(self::SESSION_OUT, $at($last, '23:55:00'))];

        $this->assertSame('/in-landing', $in['landing_page_path']);
        $this->assertSame('newsletter', $in['source']);
        $this->assertSame('/in-landing', $in['previous_page_path']);
        $this->assertSame(0, (int) $in['is_entrance']);
        $this->assertSame('/out-exit', $out['next_page_path']);
        $this->assertSame(0, (int) $out['is_exit']);
        $this->assertSame(2, (int) $out['page_depth']);
    }

    private function seed(int $yyyymmdd, int $session, int $ts, int $seq, string $path, array $row = [],
                          string $type = 'page_view', int $visitor = self::VISITOR, string $site = self::SITE): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties($row + [
            'id'            => \OWA\Module\Base\Classes\V2Event::id($site, $visitor, $session, $ts, $type),
            'event_type'    => $type,
            'site_id'       => $site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'ts'            => $ts,
            'event_seq'     => $seq,
            'yyyymmdd'      => $yyyymmdd,
            'page_location' => 'https://example.test' . $path,
            'page_path'     => $path,
            'is_goal_event' => 0,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');
    }

    private function idOf(int $session, int $ts): string
    {
        return (string) \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, $session, $ts, 'page_view');
    }

    /** Every column of the partition's rows but built_at, by id. */
    private function cubeRows(array $span): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            'SELECT * FROM %s PARTITION (%s) ORDER BY id', Cubes::tableFor(self::PROPERTY), $span['name'])) as $row) {

            unset($row['built_at']);
            $out[(string) $row['id']] = $row;
        }

        return $out;
    }
}
