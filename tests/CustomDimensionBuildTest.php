<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Dimensions;

/**
 * A registered dimension, end to end: set on a beacon, stored as JSON, promoted
 * to a column, filled by a build.
 *
 * The DDL and the arithmetic are covered by CustomDimensionsTest. This runs the
 * real ALTER against a real cube and then the real build, because everything
 * interesting between the two is SQL: the JSON path, the clamp, the join that
 * only appears when something user-scoped is registered, and whether
 * EXCHANGE PARTITION still accepts the table afterwards.
 *
 * That last one is the reason this is worth its runtime. An instant ADD COLUMN
 * -- MySQL's default -- leaves row-format metadata that makes the swap fail
 * with error 1731, and it fails on the NEXT build rather than on the ALTER. A
 * test that only checked the column existed would pass against a cube that
 * could never publish again.
 */
final class CustomDimensionBuildTest extends TestCase
{
    const SITE     = 'owa-cd-build-site';
    const PROPERTY = 7775000000000001;

    const VISITOR = 7775100000000001;
    const SESSION = 8885100000000001;

    /** @var int */
    private $yyyymmdd;

    /** @var int microseconds */
    private $t0;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Custom dimension fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = owa_coreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Custom dimension fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        self::trimToToday();
    }

    /**
     * Leave the fixture cube ONE dated partition, and the catch-all.
     *
     * Every ALTER here is a full table rebuild, and the rebuild's cost is the
     * partition count: measured on this cube, adding a column took 4,361ms
     * across its 72 dated partitions and 130ms across one. Twenty-odd ALTERs
     * is the difference between two minutes and four seconds.
     *
     * Nothing in this file is about the lead's shape -- that is
     * EventEntityTest's and PartitionOperationsTest's -- and the one thing that
     * has to be real is that EXCHANGE PARTITION still accepts the table after a
     * registration, which needs one partition to swap into.
     */
    private static function trimToToday(): void
    {
        $db    = owa_coreAPI::dbSingleton();
        $today = date('Ymd');
        $drop  = [];

        // getPartitionSpans() never returns the catch-all, so pmax survives
        // this and the cube keeps somewhere to put a stray row.
        foreach ($db->getPartitionSpans(Cubes::tableFor(self::PROPERTY)) as $span) {

            if (!($span['start'] <= $today && $today < $span['less_than'])) {
                $drop[] = $span['name'];
            }
        }

        foreach (array_chunk($drop, 40) as $chunk) {
            $db->query(sprintf('ALTER TABLE %s DROP PARTITION %s',
                Cubes::tableFor(self::PROPERTY), implode(',', $chunk)));
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
        $db = owa_coreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory($entity)->getTableName(), $db->prepare(self::SITE)));
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        $db->query(sprintf('DELETE FROM %s WHERE property_id = %d',
            owa_coreAPI::entityFactory('base.custom_dimension')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this runs a real ALTER and build.');
        }

        $this->yyyymmdd = (int) date('Ymd');
        $this->t0       = (time() - 7200) * 1000000;

        $db = owa_coreAPI::dbSingleton();

        // Every registration this Property has, so each test starts from none
        // -- in ONE ALTER, because each one is a full rebuild of a
        // 73-partition table and doing them singly is most of this file's
        // runtime.
        // Read off the TABLE, not the registry: a registration whose ALTER was
        // refused names a column that is not there, and DROPping it fails.
        $columns = Dimensions::registeredColumnsOn($this->cube());

        if (Dimensions::forProperty(self::PROPERTY) || $columns) {
            $db->query(sprintf('DELETE FROM %s WHERE property_id = %d',
                owa_coreAPI::entityFactory('base.custom_dimension')->getTableName(),
                self::PROPERTY));

            if ($columns) {
                $this->assertTrue($db->alterColumnsRebuilding($this->cube(), [], $columns));
            }
        }

        foreach (['base.event_raw', 'base.visitor_acquisition'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory($entity)->getTableName(), $db->prepare(self::SITE)));
        }
    }

    private function cube(): string
    {
        return Cubes::tableFor(self::PROPERTY);
    }

    /**
     * One raw row carrying `params`, plus a visitor store row carrying
     * `properties`.
     */
    private function seed(array $params, array $properties = []): void
    {
        $id = \OWA\Module\Base\Classes\V2Event::id(
            self::SITE, self::VISITOR, self::SESSION, $this->t0, 'page_view');

        $raw = owa_coreAPI::entityFactory('base.event_raw');
        $raw->setProperties([
            'id'            => $id,
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR,
            'session_id'    => self::SESSION,
            'ts'            => $this->t0,
            'yyyymmdd'      => $this->yyyymmdd,
            'is_goal_event' => 0,
            'page_location' => 'https://example.test/p',
            'page_path'     => '/p',
            'page_title'    => 'P',
            'params'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $this->assertTrue($raw->create(), 'seeding owa_event_raw');

        $visitor = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $visitor->setProperties([
            'visitor_id' => self::VISITOR,
            'site_id'    => self::SITE,
            'acq_ts'     => $this->t0,
            'acq_source' => 'partner',
            'acq_medium' => 'referral',
            'last_seen'  => (int) substr((string) $this->yyyymmdd, 0, 6),
            'properties' => $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $this->assertTrue($visitor->create(), 'seeding owa_visitor_acquisition');
    }

    private function rebuild(): void
    {
        $builder = new \OWA\Module\Base\Classes\Cube\Builder(self::PROPERTY);
        $spans   = $builder->partitions($this->yyyymmdd, $this->yyyymmdd);

        $this->assertNotEmpty($spans);

        foreach ($spans as $span) {
            $result = $builder->rebuild($span);

            $this->assertTrue($result['ok'], sprintf(
                'rebuild of %s failed. If the server said 1731, a column was added to the '
              . 'cube instantly and EXCHANGE PARTITION will refuse every build from now on.',
                $span['name']));
        }
    }

    /**
     * Register AND apply, which is two steps now.
     *
     * Registering writes a row; the column arrives at a reconcile, because the
     * ALTER is a full table rebuild and cannot happen inside the request that
     * asked for it. Most tests here want the column, so this does both --
     * testAPendingRegistrationIsNotBuiltUntilItIsApplied is the one that does
     * not.
     */
    private function register(array $request): void
    {
        $this->record($request);
        $this->apply();
    }

    private function record(array $request): void
    {
        $result = Dimensions::register(self::PROPERTY, [$request]);

        $this->assertTrue($result['ok'], (string) $result['error']);
    }

    private function apply(): array
    {
        $result = Dimensions::reconcile(self::PROPERTY);

        $this->assertTrue($result['ok'], (string) $result['error']);

        return $result;
    }

    /** @return array the registration row, as stored */
    private function registration(string $key): array
    {
        foreach (Dimensions::forProperty(self::PROPERTY) as $row) {
            if ($row['dimension_key'] === $key) {
                return $row;
            }
        }

        $this->fail("no registration for $key");
    }

    /** @return array|false the one built row */
    private function built()
    {
        return owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT * FROM %s WHERE visitor_id = %d', $this->cube(), self::VISITOR));
    }

    public function testAnEventPropertyReachesItsColumn(): void
    {
        $this->register(['key' => 'tier', 'scope' => 'event']);
        $this->seed(['tier' => 'gold']);
        $this->rebuild();

        $this->assertSame('gold', $this->built()['cd_tier']);
    }

    /**
     * A user property reaches its column, and so does when it was set.
     *
     * The set time is not a dimension -- a microsecond value has one bucket per
     * event -- but it is the only thing that can answer "was this value already
     * in force at this event", because a cube row carries the visitor's CURRENT
     * value on every event of theirs, including events from before they set it.
     */
    public function testAUserPropertyReachesItsColumnWithItsSetTime(): void
    {
        $this->register(['key' => 'plan', 'scope' => 'user']);
        $this->seed([], ['plan' => ['v' => 'enterprise', 'ts' => $this->t0]]);
        $this->rebuild();

        $row = $this->built();

        $this->assertSame('enterprise', $row['cd_plan']);
        $this->assertSame((string) $this->t0, (string) $row['cd_plan_set_ts']);
        $this->assertLessThanOrEqual((int) $row['ts'], (int) $row['cd_plan_set_ts'],
            'and it is comparable with the event time, which is what it is for');
    }

    /** Nothing reads an unregistered key, however faithfully it was stored. */
    public function testAnUnregisteredKeyIsCollectedAndNotQueryable(): void
    {
        $this->register(['key' => 'tier', 'scope' => 'event']);
        $this->seed(['tier' => 'gold', 'unregistered' => 'stored anyway']);
        $this->rebuild();

        $row = $this->built();

        $this->assertSame('gold', $row['cd_tier']);
        $this->assertArrayNotHasKey('cd_unregistered', $row);

        // But it IS in raw, which is what makes registering it later and
        // rebuilding bring it back -- the thing GA cannot do.
        $raw = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT params FROM %s WHERE visitor_id = %d',
            owa_coreAPI::entityFactory('base.event_raw')->getTableName(), self::VISITOR));

        $this->assertStringContainsString('unregistered', (string) $raw['params']);
    }

    /**
     * REGISTERING LATE AND BACKFILLING WORKS, which is the capability GA does
     * not have: a GA custom dimension is not retroactive, so everything
     * collected before it was registered is permanently unreportable.
     */
    public function testAKeyRegisteredAFTERCollectionBackfillsFromRaw(): void
    {
        $this->seed(['late' => 'was here all along']);
        $this->rebuild();

        $this->assertArrayNotHasKey('cd_late', (array) $this->built());

        $this->register(['key' => 'late', 'scope' => 'event']);

        // The column exists now and is NULL until a build fills it.
        $this->assertNull($this->built()['cd_late'],
            'registering adds the column; it does not reach backwards on its own');

        $this->rebuild();

        $this->assertSame('was here all along', $this->built()['cd_late'],
            'a rebuild over the range is what backfills it');
    }

    /**
     * AN OVER-LONG VALUE IS CLAMPED, NOT FATAL.
     *
     * Under STRICT_ALL_TABLES an over-long value does not truncate -- it aborts
     * the whole INSERT ... SELECT, and the partition keeps its last good
     * contents. One site setting a long string must not be able to stop a
     * Property's cube being built, so the clamp is in the expression.
     */
    public function testAValueLongerThanItsColumnIsClampedRatherThanFailingTheBuild(): void
    {
        $this->register(['key' => 'sku', 'scope' => 'event']);
        $this->seed(['sku' => str_repeat('x', 400)]);
        $this->rebuild();

        $this->assertSame(Dimensions::DIMENSION_LENGTH,
            strlen((string) $this->built()['cd_sku']));
    }

    /**
     * A value that will not convert is NULL, not a failed build.
     *
     * The declared type is the cube's decision and the JSON is the site's, so
     * they can disagree on any beacon. Measured on 8.4: JSON_VALUE with
     * RETURNING answers NULL on a conversion error rather than raising one.
     */
    public function testANonNumericValueForANumericDimensionIsNull(): void
    {
        $this->register(['key' => 'qty', 'scope' => 'event', 'type' => 'integer']);
        $this->seed(['qty' => 'not a number']);
        $this->rebuild();

        $this->assertNull($this->built()['cd_qty']);
    }

    public function testANumberReachesANumericColumnAsANumber(): void
    {
        $this->register(['key' => 'qty', 'scope' => 'event', 'type' => 'integer']);
        $this->seed(['qty' => 42]);
        $this->rebuild();

        $this->assertSame(42, (int) $this->built()['cd_qty']);
    }

    /** A row that set nothing gets NULL, and does not fail the partition. */
    public function testARowCarryingNoPropertiesAtAllGetsNull(): void
    {
        $this->register(['key' => 'tier', 'scope' => 'event']);
        $this->register(['key' => 'plan', 'scope' => 'user']);
        $this->seed([]);
        $this->rebuild();

        $row = $this->built();

        $this->assertNull($row['cd_tier']);
        $this->assertNull($row['cd_plan']);
        $this->assertNull($row['cd_plan_set_ts']);
    }

    /**
     * THE CUBE CAN STILL PUBLISH AFTER A REGISTRATION AND AFTER A DROP.
     *
     * The swap is what an instant column breaks, and it breaks on the build
     * after the ALTER rather than on the ALTER. Both directions are pinned to
     * ALGORITHM=INPLACE for this reason, and this is the assertion that would
     * catch either one being unpinned.
     */
    public function testTheSwapSurvivesBothTheAddAndTheDrop(): void
    {
        $this->register(['key' => 'tier', 'scope' => 'event']);
        $this->register(['key' => 'plan', 'scope' => 'user']);
        $this->seed(['tier' => 'gold'], ['plan' => ['v' => 'pro', 'ts' => $this->t0]]);

        $this->rebuild();

        $this->assertSame('gold', $this->built()['cd_tier']);

        $result = Dimensions::deregister(self::PROPERTY, 'plan');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(['cd_plan', 'cd_plan_set_ts'], $result['columns']);

        $this->apply();

        // The build that would fail with 1731 if either ALTER had been instant.
        $this->rebuild();

        $row = $this->built();

        $this->assertSame('gold', $row['cd_tier']);
        $this->assertArrayNotHasKey('cd_plan', $row, 'the column went with the registration');
    }

    /**
     * THE VISITOR JOIN IS DEMAND-DRIVEN, and a registration is what can demand it.
     *
     * Asserted on the statement rather than the result, because the point is
     * what the build does NOT do: a cube with nothing user-scoped registered
     * must not pay for the visitor join on account of custom dimensions.
     */
    public function testAUserScopedRegistrationIsWhatAddsTheVisitorJoin(): void
    {
        $this->register(['key' => 'tier', 'scope' => 'event']);

        $event_only = $this->statement();

        $this->assertStringContainsString('r.params', $event_only);

        $this->register(['key' => 'plan', 'scope' => 'user']);

        $this->assertStringContainsString('v.properties', $this->statement(),
            'the user-scoped one reads the visitor store');
    }

    /** The composed statement for today's partition, without running it. */
    private function statement(): string
    {
        $builder = new \OWA\Module\Base\Classes\Cube\Builder(self::PROPERTY);
        $spans   = $builder->partitions($this->yyyymmdd, $this->yyyymmdd);

        return (string) $builder->rebuild($spans[0], true)['sql'];
    }

    /**
     * A PENDING REGISTRATION DOES NOT BREAK THE BUILD.
     *
     * This is the whole risk of deferring the DDL. The registry now names a
     * column the table does not have, and a build that trusted the registry
     * would put that name in its INSERT and fail with "Unknown column in field
     * list" -- one Property's entire cube stopping because somebody registered
     * a dimension a few minutes ago. So the build fills the intersection of
     * what is registered and what exists.
     */
    public function testAPendingRegistrationIsNotBuiltUntilItIsApplied(): void
    {
        $this->record(['key' => 'later', 'scope' => 'event']);
        $this->seed(['later' => 'value']);

        $this->assertSame('pending', $this->registration('later')['state']);

        // The build has to survive this, and produce rows.
        $this->rebuild();

        $row = $this->built();

        $this->assertNotEmpty($row, 'the partition still built');
        $this->assertArrayNotHasKey('cd_later', $row, 'and the column is not there yet');

        $this->apply();

        $this->assertSame('applied', $this->registration('later')['state']);

        $this->rebuild();

        $this->assertSame('value', $this->built()['cd_later']);
    }

    /**
     * A DE-REGISTERED COLUMN IS INERT BEFORE IT IS DROPPED.
     *
     * The other half of the same window: the row is gone and the column is
     * still there. A build must simply stop filling it rather than failing, so
     * that the drop can wait for a moment when something holds the cube's lock.
     */
    public function testADeregisteredColumnStopsBeingFilledBeforeItIsDropped(): void
    {
        $this->register(['key' => 'going', 'scope' => 'event']);
        $this->seed(['going' => 'value']);
        $this->rebuild();

        $this->assertSame('value', $this->built()['cd_going']);

        Dimensions::deregister(self::PROPERTY, 'going');

        // Still on the table, no longer in the registry.
        $this->assertContains('cd_going', Dimensions::registeredColumnsOn($this->cube()));

        $this->rebuild();

        $row = $this->built();

        $this->assertNotEmpty($row, 'the build survives a column nothing names');
        $this->assertNull($row['cd_going'], 'and stops filling it');

        $this->apply();

        $this->assertNotContains('cd_going', Dimensions::registeredColumnsOn($this->cube()));
    }

    /**
     * ONE ALTER CARRIES THE ADDS AND THE DROPS TOGETHER.
     *
     * Measured on a 73-partition cube: one added column 4,361ms, two added
     * 4,247ms, one added and one dropped together 4,193ms. The rebuild is the
     * cost and the clause count is not, which is the entire argument for
     * accumulating changes rather than applying each as it arrives.
     */
    public function testOneReconcileAppliesEverythingThatAccumulated(): void
    {
        $this->register(['key' => 'old', 'scope' => 'event']);

        Dimensions::deregister(self::PROPERTY, 'old');
        $this->record(['key' => 'new1', 'scope' => 'event']);
        $this->record(['key' => 'new2', 'scope' => 'event']);

        $result = $this->apply();

        $this->assertSame(['cd_new1', 'cd_new2'], $result['added']);
        $this->assertSame(['cd_old'], $result['dropped']);
        $this->assertTrue($result['changed']);

        // And a second one has nothing left to do, so a frequent job is free.
        $again = $this->apply();

        $this->assertFalse($again['changed']);
        $this->assertSame([], $again['added']);
        $this->assertSame([], $again['dropped']);
    }

    /**
     * TWO THAT EACH FIT ALONE NEED NOT FIT TOGETHER, and only the check made
     * under the lock can see it.
     *
     * The hole is exact: the advisory check at registration prices the cube as
     * it is NOW, and a pending registration has no column yet -- so two
     * registrations made separately both pass it, and the reconcile is the
     * first moment anything sees them together. That is why the second check
     * exists and why it is the authoritative one.
     *
     * Reproduced by padding the cube until it has room for one more and then
     * registering two. With the twenty-dimension cap in place this is no longer
     * something an operator can reach by registering a lot -- it needs the cube
     * itself to have grown -- which is the cap doing its job, and is exactly
     * why the path still has to work.
     */
    public function testTwoRegistrationsThatFitAloneButNotTogetherFailUnderTheLock(): void
    {
        $db     = owa_coreAPI::dbSingleton();
        $maxlen = (int) $db->tableCharsetMaxLen($this->cube());
        $each   = Dimensions::definitionRowBytes(
            'VARCHAR(' . Dimensions::DIMENSION_LENGTH . ')', $maxlen);

        // Leave room for exactly one more dimension.
        $spare = Dimensions::MAX_ROW_BYTES - (int) $db->tableRowBytes($this->cube());
        $pad   = intdiv($spare - $each - 8, $maxlen);

        $this->assertTrue(
            $db->alterColumnsRebuilding($this->cube(), ['filler' => "VARCHAR($pad) NULL"]),
            'padding the cube to leave room for one');

        // Each passes its own advisory check, because neither column exists yet.
        foreach (['first', 'second'] as $key) {
            $result = Dimensions::register(self::PROPERTY, [['key' => $key, 'scope' => 'event']]);

            $this->assertTrue($result['ok'], "$key: " . $result['error']);
        }

        $reconciled = Dimensions::reconcile(self::PROPERTY);

        $this->assertNotEmpty($reconciled['skipped'],
            'the reconcile is the first thing to see both at once');
        $this->assertStringContainsString('row left', reset($reconciled['skipped']));

        $states = [$this->registration('first')['state'], $this->registration('second')['state']];

        sort($states);

        $this->assertSame(['failed', 'failed'], $states,
            'the batch is refused whole rather than half-applied');
        $this->assertNotEmpty($this->registration('first')['state_message'],
            'and says why, because the person who registered it is long gone');

        // The cube still builds. One bad registration must not cost a Property
        // its reporting.
        $this->seed(['first' => 'x']);
        $this->rebuild();

        $this->assertNotEmpty($this->built());

        $this->assertTrue($db->alterColumnsRebuilding($this->cube(), [], ['filler']));
    }

    /**
     * An APPLIED registration names a column that is really there.
     *
     * The state is written from what the table shows rather than from what the
     * ALTER was asked to do, so it can never claim more than the cube can back
     * up.
     */
    public function testEveryAppliedRegistrationNamesAColumnThatExists(): void
    {
        $this->register(['key' => 'plan', 'scope' => 'user']);

        $db      = owa_coreAPI::dbSingleton();
        $present = [];

        foreach ((array) $db->get_results(sprintf(
                "SHOW COLUMNS FROM %s LIKE 'cd\\_%%'", $this->cube())) as $row) {
            $present[] = reset($row);
        }

        foreach (Dimensions::forProperty(self::PROPERTY) as $row) {
            foreach (array_keys(Dimensions::columnsOf($row)) as $column) {
                $this->assertContains($column, $present, $column);
            }
        }
    }
}
