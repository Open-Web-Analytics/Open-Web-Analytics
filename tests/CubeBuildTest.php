<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A build, end to end: raw rows in, cube rows out of one Property's cube.
 *
 * THE FIXTURE OWNS A PROPERTY. There is no owa_event -- a cube belongs to a
 * Property and holds the rows of that Property's profiles -- so the fixture
 * creates a Property, a profile under it, and the cube, and drops all three
 * afterwards. That also makes the site filter testable: raw is shared, and
 * everything else in it has to stay out of this cube.
 *
 * Runs the real statement and the real swap against the configured database,
 * because every interesting part of this is SQL -- the window frames, the
 * classifier, the join to the visitor store -- and none of it can be asserted
 * by reading PHP.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE. Three visitors that differ in what they
 * arrived with and in whether the visitor store knows them, and one session
 * still running. A fixture where every row carries the same shape passes
 * against a statement that stamps one row's values onto all of them.
 *
 * It rebuilds a whole partition, which is the unit the swap works in, so the
 * fixture rows are removed and the partition rebuilt again in tearDown.
 */
final class CubeBuildTest extends TestCase
{
    /** Visitors, sessions and events of the fixture, all numeric ids. */
    const SITE = 'owa-denorm-test-site';

    /** A second profile of the SAME Property: its rows belong in the same cube. */
    const OTHER_SITE = 'owa-denorm-other-site';

    /** A profile of a DIFFERENT Property: its rows must stay out of it. */
    const FOREIGN_SITE = 'owa-denorm-foreign-site';

    /** The Property the fixture's cube belongs to, and the one beside it. */
    const PROPERTY         = 7779000000000001;
    const FOREIGN_PROPERTY = 7779000000000002;

    const VISITOR_TAGGED  = 7771000000000001;
    const VISITOR_REFERRED = 7771000000000002;
    const VISITOR_DIRECT  = 7771000000000003;
    const VISITOR_OPEN    = 7771000000000004;
    const VISITOR_LONG_REF = 7771000000000005;
    const VISITOR_LONG_HOST = 7771000000000006;
    const VISITOR_SEARCHER  = 7771000000000007;
    const VISITOR_TAGGED_SEARCH = 7771000000000008;
    const VISITOR_SECOND_PROFILE = 7771000000000010;
    const VISITOR_FOREIGN        = 7771000000000011;

    /** @var array id => row, as seeded */
    private $seeded = [];

    /** @var int */
    private $yyyymmdd;

    /**
     * The fixture's clock, fixed once per test.
     *
     * An event id is derived from its ts, so a helper calling time() again
     * would compute a different id the moment the second ticked over -- and
     * look up a row that was never seeded.
     *
     * @var int microseconds, two hours ago, so the seeded sessions read closed
     */
    private $t0;

    /** @var int microseconds, now: a session still inside the idle timeout */
    private $t_open;

    /**
     * The Property, its profiles and its cube: made ONCE for the whole class.
     *
     * Creating a cube is seventy-odd partitions and about 2.7 seconds. Per test
     * that was 26 creates and 26 drops -- most of the file's runtime spent on
     * DDL that no test is about. What each test does need is its own rows, and
     * a rebuild REPLACES a partition, so re-seeding and rebuilding gives every
     * test a clean cube without the table going anywhere.
     */
    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
        self::seedProperty();
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping cube build test.');
        }

        $this->yyyymmdd = (int) date('Ymd');
        $this->t0       = (time() - 7200) * 1000000;
        $this->t_open   = time() * 1000000;

        self::clearRows();
        $this->seedRaw();
        $this->seedVisitorStore();
        $this->rebuild();
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::clearRows();

        // The cube stays, holding what raw now says, which is nothing of ours.
        $this->rebuild();
    }

    /** The fixture's rows in the two shared tables. */
    private static function clearRows(): void
    {
        $db = owa_coreAPI::dbSingleton();

        foreach ([self::SITE, self::OTHER_SITE, self::FOREIGN_SITE] as $site) {
            foreach (['base.event_raw', 'base.visitor_acquisition'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    owa_coreAPI::entityFactory($entity)->getTableName(), $db->prepare($site)));
            }
        }
    }

    /**
     * Everything the fixture makes, in any order it may be in.
     *
     * Run before seeding as well as after, so a crashed run does not leave the
     * next one failing on a duplicate key or an existing table.
     */
    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        self::clearRows();

        foreach ([self::SITE, self::OTHER_SITE, self::FOREIGN_SITE] as $site) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory('base.site')->getTableName(), $db->prepare($site)));
        }

        foreach ([self::PROPERTY, self::FOREIGN_PROPERTY] as $property_id) {
            $db->query(sprintf('DELETE FROM %s WHERE id = %d',
                owa_coreAPI::entityFactory('base.property')->getTableName(), $property_id));

            // The cube goes with the Property, and so do its working tables.
            // One per Property means a fixture that leaves its own behind
            // leaves seventy partitions on the developer's installation.
            $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor($property_id);

            foreach (['', '_rebuild', '_computed'] as $suffix) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
            }
        }
    }

    /**
     * Two Properties: the fixture's, with two profiles, and one beside it.
     *
     * Both halves are the point. Raw is shared and the cubes are not, so a
     * build has to take every profile of ITS Property -- which is why there are
     * two -- and nothing from any other, which is why there is a second
     * Property whose rows sit in the same partition of raw.
     */
    private static function seedProperty(): void
    {
        foreach ([
            self::PROPERTY         => [self::SITE, self::OTHER_SITE],
            self::FOREIGN_PROPERTY => [self::FOREIGN_SITE],
        ] as $property_id => $sites) {

            $property = owa_coreAPI::entityFactory('base.property');
            $property->setProperties([
                'id'            => $property_id,
                'name'          => 'Cube build fixture',
                'domain'        => 'example.test',
                'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
                'creation_date' => time(),
            ]);

            if (!$property->create()) {
                throw new \RuntimeException('seeding owa_property failed');
            }

            foreach ($sites as $i => $site_id) {
                $site = owa_coreAPI::entityFactory('base.site');
                $site->setProperties([
                    // A profile's primary key is `id`; site_id is the string
                    // the beacon quotes. Derived from the Property's, and from
                    // the position under it, so a crashed run leaves nothing
                    // for the next one to collide with.
                    'id'          => $property_id * 10 + $i,
                    'site_id'     => $site_id,
                    'property_id' => $property_id,
                    'name'        => 'Cube build fixture profile',
                    'domain'      => 'example.test',
                ]);

                if (!$site->create()) {
                    throw new \RuntimeException('seeding owa_site failed');
                }
            }
        }

        if (!\OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture Property\'s cube failed');
        }
    }

    private function table(string $entity): string
    {
        return owa_coreAPI::entityFactory($entity)->getTableName();
    }

    /** The fixture Property's cube. */
    private function cube(): string
    {
        return \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);
    }

    private function seedRaw(): void
    {
        $t = $this->t0;

        // A tagged arrival, three events, one session. Only the landing event
        // carries tags -- which is the whole reason a build exists.
        $this->seed('page_view', self::VISITOR_TAGGED, 8881000000000001, $t, [
            'page_location'   => 'https://example.test/landing?utm_source=newsletter',
            'page_path'       => '/landing',
            'page_query'      => 'utm_source=newsletter',
            'page_title'      => 'Landing',
            'tagged_source'   => 'newsletter',
            'tagged_medium'   => 'email',
            'tagged_campaign' => 'spring',
            'tagged_ad'       => 'banner-a',
        ]);

        $this->seed('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000, [
            'page_location' => 'https://example.test/two',
            'page_path'     => '/two',
            'page_title'    => 'Two',
            'prev_event_ts' => $t,
        ]);

        $this->seed('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000, [
            'page_location' => 'https://example.test/two',
            'page_path'     => '/two',
            'page_title'    => 'Two',
        ]);

        // No tags, a search engine referrer, and no row in the visitor store.
        $this->seed('page_view', self::VISITOR_REFERRED, 8881000000000002, $t, [
            'page_location' => 'https://example.test/from-search',
            'page_path'     => '/from-search',
            'page_title'    => 'From search',
            'referer_url'   => 'https://www.google.com/search?q=web+analytics',
            'referer_host'  => 'google.com',
            // No referer_query: modern engines send the host and nothing else,
            // which is the case the fallback has to answer NULL for.
        ]);

        // Nothing at all: no tags, no referrer.
        $this->seed('page_view', self::VISITOR_DIRECT, 8881000000000003, $t, [
            'page_location' => 'https://example.test/direct',
            'page_path'     => '/direct',
            'page_title'    => 'Direct',
        ]);

        // A referrer that is not a URL at all: no scheme, so there is no host
        // in it to read.
        $this->seed('page_view', self::VISITOR_LONG_REF, 8881000000000005, $t, [
            'page_location' => 'https://example.test/long-referrer',
            'page_path'     => '/long-referrer',
            'page_title'    => 'Long referrer',
            // No scheme, so V2Event::parseUrl() finds no host and ingest
            // stores NULL. A build must answer direct, not invent a source.
            'referer_url'   => str_repeat('a', 900),
        ]);

        // A syntactically plausible host, longer than a domain name may be.
        $this->seed('page_view', self::VISITOR_LONG_HOST, 8881000000000006, $t, [
            'page_location' => 'https://example.test/long-host',
            'page_path'     => '/long-host',
            'page_title'    => 'Long host',
            // parse_url() calls this a host; it is longer than a domain name
            // may be, so parseUrl() refuses it and ingest stores NULL.
            'referer_url'   => 'https://' . str_repeat('b', 300) . '/x',
        ]);

        // An engine that still puts the term in its referrer. Yandex is the
        // only one measured doing it since 2022 -- 8 sessions -- which is why
        // the compute step exists for the mechanism rather than the volume.
        $this->seed('page_view', self::VISITOR_SEARCHER, 8881000000000007, $t, [
            'page_location' => 'https://example.test/found',
            'page_path'     => '/found',
            'page_title'    => 'Found',
            'referer_url'   => 'https://yandex.ru/search/?text=open+web+analytics',
            'referer_host'  => 'yandex.ru',
            'referer_query' => 'text=open+web+analytics',
        ]);

        // A tagged arrival that ALSO has an engine query. The tag wins: a
        // compute step fills, it never overwrites.
        $this->seed('page_view', self::VISITOR_TAGGED_SEARCH, 8881000000000008, $t, [
            'page_location'       => 'https://example.test/both',
            'page_path'           => '/both',
            'page_title'          => 'Both',
            'referer_url'         => 'https://yandex.ru/search/?text=from+the+referrer',
            'referer_host'        => 'yandex.ru',
            'referer_query'       => 'text=from+the+referrer',
            'tagged_search_terms' => 'from the tag',
        ]);

        // Still inside the idle timeout, so its last event is not an exit yet.
        $this->seed('page_view', self::VISITOR_OPEN, 8881000000000004, $this->t_open, [
            'page_location' => 'https://example.test/open',
            'page_path'     => '/open',
            'page_title'    => 'Open',
        ]);

        // The SAME Property's second profile. Its rows belong in this cube.
        $this->seed('page_view', self::VISITOR_SECOND_PROFILE, 8881000000000010, $t, [
            'page_location' => 'https://example.test/second-profile',
            'page_path'     => '/second-profile',
            'page_title'    => 'Second profile',
        ], self::OTHER_SITE);

        // A DIFFERENT Property's, in the same partition of the same raw table.
        // Its rows must not reach this cube.
        $this->seed('page_view', self::VISITOR_FOREIGN, 8881000000000011, $t, [
            'page_location' => 'https://example.test/foreign',
            'page_path'     => '/foreign',
            'page_title'    => 'Foreign',
        ], self::FOREIGN_SITE);
    }

    /**
     * One raw row, with the id a build will find it under.
     */
    private function seed(string $type, int $visitor, int $session, int $ts, array $row,
                          string $site = self::SITE): void
    {
        $id = \OWA\Module\Base\Classes\V2Event::id($site, $visitor, $session, $ts, $type);

        $row += [
            'id'            => $id,
            'event_type'    => $type,
            'site_id'       => $site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'ts'            => $ts,
            'yyyymmdd'      => $this->yyyymmdd,
            'is_goal_event' => 0,
        ];

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties($row);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');

        $this->seeded[$id] = $row;
    }

    /**
     * An acquisition for the tagged visitor only. The others have no row, which
     * is what the sentinel is for.
     */
    private function seedVisitorStore(): void
    {
        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id'   => self::VISITOR_TAGGED,
            'site_id'      => self::SITE,
            'acq_source'   => 'partner-site',
            'acq_medium'   => 'referral',
            'acq_campaign' => 'launch',
            'acq_ad'       => 'tile-b',
            'acq_referer_host' => null,
            'acq_ts'       => $this->t0,
            'last_seen'    => (int) substr((string) $this->yyyymmdd, 0, 6),
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_visitor_acquisition');
    }

    private function rebuild(): void
    {
        $builder = new \OWA\Module\Base\Classes\Cube\Builder(self::PROPERTY);
        $spans = $builder->partitions($this->yyyymmdd, $this->yyyymmdd);

        $this->assertNotEmpty($spans, $this->cube() . ' has no dated partition covering today');

        foreach ($spans as $span) {
            $result = $builder->rebuild($span);
            $this->assertTrue($result['ok'], 'rebuild of ' . $span['name'] . ' failed');
        }
    }

    /**
     * One cube row, by the id its raw row was seeded under.
     */
    private function built(string $type, int $visitor, int $session, int $ts): array
    {
        $id = \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, $type);

        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT * FROM %s WHERE id = %d AND yyyymmdd = %d',
            $this->cube(), $id, $this->yyyymmdd));

        $this->assertNotEmpty($row, "no cube row for $type/$visitor");

        return $row;
    }

    /**
     * The staging table is created UNPARTITIONED, and cheaply.
     *
     * EXCHANGE PARTITION refuses a partitioned table, so the partitions have to
     * go -- and CREATE TABLE LIKE then REMOVE PARTITIONING created all 72 of
     * the cube's only to delete them again: 4,181ms of a 4,317ms build, against
     * 136ms for everything that does actual work. Taking the cube's own DDL and
     * cutting the partition clause off costs 55ms.
     *
     * Derived from the LIVE table rather than from the entity, because the swap
     * compares column for column and the cube may carry columns no entity
     * declares -- a registered custom dimension is added at runtime.
     */
    public function testStagingIsBuiltFlatFromTheCubesOwnShape(): void
    {
        $db      = owa_coreAPI::dbSingleton();
        $cube    = $this->cube();
        $staging = $cube . '_rebuild';

        $db->query("DROP TABLE IF EXISTS $staging");
        $this->assertTrue($db->createUnpartitionedCopy($staging, $cube));

        $parts = $db->get_row(
            "SELECT COUNT(*) AS n FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$staging'
                AND PARTITION_NAME IS NOT NULL");

        $this->assertSame(0, (int) $parts['n'], 'the swap refuses a partitioned table');

        // Same columns, same order, same types -- which is what 1736 checks.
        $shape = function ($table) use ($db) {
            $out = [];
            foreach ($db->get_results(
                "SELECT COLUMN_NAME c, COLUMN_TYPE t FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
               ORDER BY ORDINAL_POSITION") as $row) {
                $row = (array) $row;
                $out[] = $row['c'] . ':' . $row['t'];
            }
            return $out;
        };

        $this->assertSame($shape($cube), $shape($staging),
            'column for column, or EXCHANGE PARTITION refuses it');

        $db->query("DROP TABLE IF EXISTS $staging");
    }

    /** It copies a column the entity never declared, which is the point. */
    public function testStagingCopiesARuntimeAddedColumn(): void
    {
        $db      = owa_coreAPI::dbSingleton();
        $cube    = $this->cube();
        $staging = $cube . '_rebuild';

        $db->query("ALTER TABLE $cube ADD COLUMN cd_probe VARCHAR(32) NULL, ALGORITHM=INPLACE");
        $db->query("DROP TABLE IF EXISTS $staging");

        try {
            $this->assertTrue($db->createUnpartitionedCopy($staging, $cube));

            $found = $db->get_row("SHOW COLUMNS FROM $staging LIKE 'cd_probe'");

            $this->assertNotNull($found,
                'a registered custom dimension is added at runtime; staging has to carry it');
        } finally {
            $db->query("DROP TABLE IF EXISTS $staging");
            $db->query("ALTER TABLE $cube DROP COLUMN cd_probe, ALGORITHM=INPLACE");
        }
    }

    public function testOwaEventCarriesNoInstantColumnHistory(): void
    {
        // MySQL 8 adds a column instantly by default, and the row-format
        // metadata that leaves behind makes EXCHANGE PARTITION refuse the
        // table against a CREATE TABLE ... LIKE copy of it:
        //
        //   1731 Non matching attribute 'INSTANT COLUMN(s)'
        //
        // A build then fails every run having published nothing. Any update
        // touching owa_event has to recreate it rather than ALTER it.
        //
        // Only an UPGRADED install can fail this -- a fresh one never has an
        // ALTER in its history -- which is why CI alone would not have caught
        // the change that introduced it.
        $db = owa_coreAPI::dbSingleton();

        $columns = $db->get_results(
            "SELECT COLUMN_NAME c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = 'information_schema'
                AND TABLE_NAME = 'INNODB_TABLES'
                AND COLUMN_NAME IN ('INSTANT_COLS', 'TOTAL_ROW_VERSIONS')");

        $available = array_map(function ($r) { return $r['c']; }, (array) $columns);

        if (!$available) {
            $this->markTestSkipped('This server does not report instant-column history.');
        }

        $table = $this->cube();
        $tests = [];

        foreach ($available as $column) {
            $tests[] = $column . ' > 0';
        }

        // Exactly this table and its partitions -- `owa_event%` would also
        // match owa_event_raw, which is never a swap target and may carry all
        // the instant history it likes.
        $rows = $db->get_results(sprintf(
            "SELECT NAME FROM information_schema.INNODB_TABLES
              WHERE (NAME LIKE '%%/%s' OR NAME LIKE '%%/%s#p#%%') AND (%s)",
            $table, $table, implode(' OR ', $tests)));

        $this->assertEmpty((array) $rows, sprintf(
            '%s carries instant-column history, so the partition swap will be refused. '
          . 'An update that adds a column to it must drop and recreate it.', $table));
    }

    public function testTheCubeCarriesEveryRawColumnPhysically(): void
    {
        // The cube is raw's columns verbatim plus the derived ones, and the
        // ENTITY says so -- but an update that adds a column to raw and forgets
        // the cube leaves the two tables disagreeing, and the next build dies
        // on "Unknown column in field list". That has happened twice.
        $db = owa_coreAPI::dbSingleton();

        $columns = function (string $table) use ($db): array {
            $names = [];
            foreach ((array) $db->get_results(
                "SELECT COLUMN_NAME c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'") as $row) {
                $names[] = $row['c'];
            }
            sort($names);
            return $names;
        };

        $missing = array_diff($columns($this->table('base.event_raw')),
                              $columns($this->cube()));

        $this->assertSame([], array_values($missing), sprintf(
            '%s is missing columns %s has. An update added them to raw only.',
            $this->cube(), $this->table('base.event_raw')));
    }

    /**
     * A cube holds EVERY profile of its Property.
     *
     * A Property may be measured more than one way -- that is what a profile is
     * -- and a report over the Property has to see all of it. So the build's
     * filter is the Property's site ids, not one site id.
     */
    public function testEveryProfileOfThePropertyIsInItsCube(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $row = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s'",
            $this->cube(), $db->prepare(self::OTHER_SITE)));

        $this->assertSame(1, (int) $row['n'],
            'the second profile of this Property is measured by the same cube');
    }

    /**
     * And NOTHING of any other Property's.
     *
     * Raw is shared: the foreign row sits in the same partition of the same
     * table, one row away. Without the site filter a build would publish every
     * Property's traffic into every Property's cube, and the row-count check
     * would not catch it -- rows in would equal rows out, just the wrong rows.
     */
    public function testAnotherPropertysRowsStayOutOfThisCube(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $foreign = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $this->table('base.event_raw'), $db->prepare(self::FOREIGN_SITE), $this->yyyymmdd));

        $this->assertSame(1, (int) $foreign['n'],
            'the foreign row has to BE in raw for its absence from the cube to mean anything');

        $row = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s'",
            $this->cube(), $db->prepare(self::FOREIGN_SITE)));

        $this->assertSame(0, (int) $row['n']);

        // And the cube holds nothing else either -- the installation's own
        // traffic is in raw too, and a missing filter would sweep that in.
        $stray = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM %s WHERE site_id NOT IN ('%s', '%s')",
            $this->cube(), $db->prepare(self::SITE), $db->prepare(self::OTHER_SITE)));

        $this->assertSame(0, (int) $stray['n'],
            'a cube holds its own Property and nothing else');
    }

    /**
     * A Property with no profiles left builds an EMPTY partition.
     *
     * The filter is a list of site ids, and an empty list has to mean "nothing
     * matches" rather than "no restriction" -- the second reading would hand a
     * Property that has lost its profiles every other Property's traffic, and
     * the row-count check would agree with it, because rows in would equal rows
     * out.
     *
     * It is a state a cube really reaches: profiles get deleted or moved, and
     * the cube outlives them until somebody removes it.
     */
    public function testACubeWhoseProfilesAreGoneGetsNothingRatherThanEverything(): void
    {
        $db   = owa_coreAPI::dbSingleton();
        $site = $this->table('base.site');

        $db->query(sprintf('UPDATE %s SET property_id = NULL WHERE property_id = %d',
            $site, self::PROPERTY));

        try {
            $this->rebuild();

            $row = $db->get_row(sprintf('SELECT COUNT(*) AS n FROM %s', $this->cube()));

            $this->assertSame(0, (int) $row['n'],
                'no profiles means no rows, not every row in raw');
        } finally {
            $db->query(sprintf('UPDATE %s SET property_id = %d WHERE site_id IN (\'%s\', \'%s\')',
                $site, self::PROPERTY,
                $db->prepare(self::SITE), $db->prepare(self::OTHER_SITE)));
        }
    }

    public function testEveryRawRowBecomesExactlyOneEventRow(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $in = $db->get_row(sprintf("SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $this->table('base.event_raw'), $db->prepare(self::SITE), $this->yyyymmdd));

        $out = $db->get_row(sprintf("SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $this->cube(), $db->prepare(self::SITE), $this->yyyymmdd));

        $this->assertSame(10, (int) $in['n']);
        $this->assertSame((int) $in['n'], (int) $out['n'],
            'A build enriches. It creates nothing and drops nothing.');
    }

    public function testTheSessionsTagsAreStampedOnEveryRowOfIt(): void
    {
        $t = $this->t0;

        // The second and third events carry no tags of their own.
        foreach ([
            ['page_view', $t + 60000000],
            ['click', $t + 120000000],
        ] as [$type, $ts]) {

            $row = $this->built($type, self::VISITOR_TAGGED, 8881000000000001, $ts);

            $this->assertSame('newsletter', $row['source'], "$type source");
            $this->assertSame('email', $row['medium'], "$type medium");
            $this->assertSame('spring', $row['campaign'], "$type campaign");
            $this->assertSame('banner-a', $row['ad'], "$type ad");

            $this->assertNull($row['tagged_source'],
                'raw still says what it observed: no tags on a mid-session row');
        }
    }

    public function testTheLandingPageIsStampedOnEveryRowOfTheSession(): void
    {
        $row = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $this->t0 + 120000000);

        $this->assertSame('https://example.test/landing?utm_source=newsletter',
            $row['landing_page_location']);
        $this->assertSame('/landing', $row['landing_page_path']);
        $this->assertSame('utm_source=newsletter', $row['landing_page_query']);
        $this->assertSame('Landing', $row['landing_page_title']);

        // The row's own page is unchanged: the landing page is a second fact
        // about it, not a replacement.
        $this->assertSame('/two', $row['page_path']);
    }

    public function testAnUntaggedReferrerIsClassified(): void
    {
        $row = $this->built('page_view', self::VISITOR_REFERRED, 8881000000000002, $this->t0);

        $this->assertSame('google.com', $row['source'], 'the referring host, without www');
        $this->assertSame('organic-search', $row['medium']);
        $this->assertNull($row['campaign'], 'no tag means no campaign, which is an absence');
    }

    public function testNoReferrerAndNoTagsIsDirect(): void
    {
        $row = $this->built('page_view', self::VISITOR_DIRECT, 8881000000000003, $this->t0);

        $this->assertSame('direct', $row['source']);
        $this->assertSame('direct', $row['medium']);
    }

    public function testAcquisitionIsStampedFromTheVisitorStore(): void
    {
        // Seeded acquisition differs from the session's own attribution, so a
        // statement copying `source` into acq_source would fail here.
        $row = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $this->t0 + 120000000);

        $this->assertSame('partner-site', $row['acq_source']);
        $this->assertSame('referral', $row['acq_medium']);
        $this->assertSame('launch', $row['acq_campaign']);
        $this->assertSame('tile-b', $row['acq_ad']);
    }

    /**
     * A visitor whose store row carries no campaign gets NULL, not the sentinel
     * -- and above all does not fail the build.
     *
     * THE DISTINCTION THE SENTINEL EXISTS FOR. "No row in the store" is
     * unresolved; "a row that recorded no campaign" is an ordinary absence, and
     * most first visits are untagged so it is the common case, not the edge.
     *
     * acq_campaign and acq_ad were declared NOT NULL with the two that actually
     * resolve, and one untagged visitor then failed the INSERT for the WHOLE
     * partition -- "Column 'acq_campaign' cannot be null" -- leaving the
     * partition at whatever the last good build wrote, because a failed build
     * does not swap. It went unnoticed while the only visitor rows in existence
     * were seeded by this file with every column filled.
     */
    public function testAnUntaggedVisitorGetsNullAcquisitionAndDoesNotFailTheBuild(): void
    {
        $visitor = 8881000000000009;

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id'       => $visitor,
            'site_id'          => self::SITE,
            'acq_source'       => null,
            'acq_medium'       => null,
            'acq_campaign'     => null,   // untagged: the common case
            'acq_ad'           => null,
            'acq_referer_host' => null,
            'acq_ts'           => $this->t0,
            'last_seen'        => (int) substr((string) $this->yyyymmdd, 0, 6),
        ]);

        $this->assertTrue($entity->create(), 'seeding an untagged visitor');

        $this->seed('page_view', $visitor, 8881000000000010, $this->t0, [
            'page_location' => 'https://example.test/untagged',
            'page_path'     => '/untagged',
            'page_title'    => 'Untagged',
        ]);

        // The build has to survive it. Before the fix this threw the whole
        // partition away.
        $this->rebuild();

        $row = $this->built('page_view', $visitor, 8881000000000010, $this->t0);

        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertNull($row['acq_campaign'], 'recorded no campaign, which is not "unresolved"');
        $this->assertNull($row['acq_ad']);
        $this->assertNotSame($sentinel, $row['acq_campaign']);

        // The two that RESOLVE still do: no referring host is `direct`, which
        // is a value, so they stay NOT NULL.
        $this->assertNotNull($row['acq_source']);
        $this->assertNotNull($row['acq_medium']);
    }

    /**
     * A row with no acquisition is still "unresolved".
     *
     * THE TEST IS acq_ts, NOT THE ROW. A user property can create a visitor row
     * for someone whose acquisition is unknown; if the sentinel still keyed on
     * row presence, the build would resolve acq_source to `direct` -- an
     * unknown silently becoming a claim -- because a NULL referring host
     * classifies as direct.
     */
    public function testAVisitorRowWithNoAcquisitionStillGetsTheSentinel(): void
    {
        $visitor = 8881000000000011;

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id' => $visitor,
            'site_id'    => self::SITE,
            'acq_ts'     => null,          // never captured
            'properties' => json_encode(['plan' => ['v' => 'enterprise', 'ts' => $this->t0]]),
            'last_seen'  => (int) substr((string) $this->yyyymmdd, 0, 6),
        ]);

        $this->assertTrue($entity->create(), 'seeding a property-only visitor row');

        $this->seed('page_view', $visitor, 8881000000000012, $this->t0, [
            'page_location' => 'https://example.test/props',
            'page_path'     => '/props',
            'page_title'    => 'Props',
        ]);

        $this->rebuild();

        $row      = $this->built('page_view', $visitor, 8881000000000012, $this->t0);
        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertSame($sentinel, $row['acq_source'],
            'the row exists but the acquisition does not, so this is unresolved');
        $this->assertSame($sentinel, $row['acq_medium']);
        $this->assertNotSame('direct', $row['acq_source'],
            'the failure this guards: unknown resolving to a claim');
    }

    public function testAVisitorWithNoStoreRowGetsTheSentinel(): void
    {
        $row = $this->built('page_view', self::VISITOR_REFERRED, 8881000000000002, $this->t0);

        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertSame($sentinel, $row['acq_source']);
        $this->assertSame($sentinel, $row['acq_medium']);
        $this->assertSame($sentinel, $row['acq_campaign']);
        $this->assertSame($sentinel, $row['acq_ad']);

        // NULL and the sentinel are different statements, and the row makes
        // both: nothing was observed, and nothing could be resolved.
        $this->assertNull($row['acq_search_terms']);
    }

    public function testAReferrerThatIsNotAUrlResolvesToDirect(): void
    {
        // parse_url() finds no host without `://`, and v1 answers direct.
        // SUBSTRING_INDEX would hand back the whole 900-character string, which
        // is both a fake domain and too wide for the column -- and under
        // STRICT_ALL_TABLES too wide aborts the INSERT, so setUp()'s rebuild
        // would fail before reaching this.
        $row = $this->built('page_view', self::VISITOR_LONG_REF, 8881000000000005, $this->t0);

        $this->assertSame('direct', $row['source']);
        $this->assertSame('direct', $row['medium']);
    }

    public function testAHostLongerThanADomainNameCanBeIsRefused(): void
    {
        // Well-formed enough for parse_url to call it a host, but longer than
        // RFC 1035 allows a domain name to be, so it is not one.
        $row = $this->built('page_view', self::VISITOR_LONG_HOST, 8881000000000006, $this->t0);

        $this->assertSame('direct', $row['source']);
    }

    public function testAComputeStepFillsWhatSqlCannot(): void
    {
        // Pulling a named parameter out of a referring URL and percent-decoding
        // it is PHP -- MySQL has no URL decode -- so before compute steps this
        // reading had nowhere to go but ingest, the one layer a corrected
        // engine list never reaches.
        $row = $this->built('page_view', self::VISITOR_SEARCHER, 8881000000000007, $this->t0);

        $this->assertSame('open web analytics', $row['search_terms'],
            'the + separators decode to spaces, which is the half SQL cannot do');
    }

    public function testTheCandidateQueryExcludesRowsSqlAlreadyAnswers(): void
    {
        // A tagged arrival that also carries an engine query. It is not a
        // candidate at all -- `when` says tagged_search_terms IS NULL -- so PHP
        // never sees it and the tag stands.
        //
        // This does NOT test the COALESCE: with the row excluded there is no
        // computed value, so the fallback wins whichever way round the two are.
        // CubeStepTest asserts the expression shape directly.
        $row = $this->built('page_view', self::VISITOR_TAGGED_SEARCH, 8881000000000008, $this->t0);

        $this->assertSame('from the tag', $row['search_terms']);
    }

    public function testAnEngineThatWithheldTheTermGetsNullNotASentinel(): void
    {
        // v1 writes '(not provided)' here, and it is the most common "search
        // term" on the demo install -- 10,773 of them against 1,530 for the
        // top real one. A sentinel that looks like an observation is what 2.11
        // refuses.
        $row = $this->built('page_view', self::VISITOR_REFERRED, 8881000000000002, $this->t0);

        $this->assertNull($row['search_terms']);
    }

    public function testIsExitMarksTheLastEventOfAClosedSession(): void
    {
        $t = $this->t0;

        $this->assertSame(0, (int) $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t)['is_exit']);
        $this->assertSame(0, (int) $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000)['is_exit']);
        $this->assertSame(1, (int) $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000)['is_exit']);
    }

    public function testASessionStillInsideTheTimeoutHasNoExit(): void
    {
        // The last event of a running session is not the exit yet, and a later
        // rebuild is what settles it.
        $row = $this->built('page_view', self::VISITOR_OPEN, 8881000000000004, $this->t_open);

        $this->assertSame(0, (int) $row['is_exit']);
    }

    public function testPrevEventTsIsCopiedFromRawRatherThanDerived(): void
    {
        // It is an observation now, carried on the beacon and corrected to
        // server time at ingest -- a build copies it like any raw column. The
        // second window function it used to need cost 128 of 195 seconds at a
        // million rows, for this one value.
        $t = $this->t0;

        $first  = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t);
        $second = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000);

        $this->assertNull($first['prev_event_ts'], 'nothing was carried on the first event');
        $this->assertSame($t, (int) $second['prev_event_ts']);
    }

    public function testBuiltAtIsStampedAndConstantWithinThePartition(): void
    {
        $t = $this->t0;

        $a = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t);
        $b = $this->built('page_view', self::VISITOR_DIRECT, 8881000000000003, $t);

        $this->assertGreaterThan(0, (int) $a['built_at']);
        $this->assertSame($a['built_at'], $b['built_at'],
            'One run writes one partition, so the as-of is the same on all of it.');
    }

    public function testRebuildingTwiceProducesTheSameRows(): void
    {
        $t   = $this->t0;
        $before = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000);

        $this->rebuild();

        $after = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000);

        unset($before['built_at'], $after['built_at']);

        $this->assertSame($before, $after,
            'Convergent: the scheduler runs a missed occurrence once, not once per occurrence.');
    }

    public function testLastSeenIsAdvancedForVisitorsInThePartition(): void
    {
        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT last_seen FROM %s WHERE visitor_id = %d',
            $this->table('base.visitor_acquisition'), self::VISITOR_TAGGED));

        $this->assertSame((int) substr((string) $this->yyyymmdd, 0, 6), (int) $row['last_seen']);
    }
}
