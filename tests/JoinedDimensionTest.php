<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\DimensionExpression;
use OWA\Module\Base\Classes\V2Event;

/**
 * A dimension whose value is several columns joined.
 *
 * WHY THIS IS NOT COVERED BY CubeReportingTest. That file proves a dimension
 * reaches the right cube; this one proves the OTHER thing a joined dimension
 * depends on, which is that a NULL part does not take the rest of the value
 * with it. Four of the six declared dimensions join a nullable column, and a
 * plain CONCAT answers NULL when any argument is NULL -- so every page without
 * a query string would have merged into a single `(not set)` row under a total
 * that still looked right. A fixture with no absent values cannot see that,
 * which is why this one is built around absence.
 */
final class JoinedDimensionTest extends TestCase
{
    const SITE     = 'owa-joined-dimension-site';
    const PROPERTY = 7776000000000077;

    const VISITOR = 7776100000000077;
    const SESSION = 8886100000000077;

    /**
     * The four shapes a joined pair can take, plus the sentinel.
     *
     * [page_path, page_query, acq_source, acq_medium]
     */
    private static function fixtureRows(): array
    {
        return [
            // both parts present
            ['/pricing', 'plan=pro', 'google', 'organic-search'],
            ['/pricing', 'plan=pro', 'google', 'organic-search'],
            // the nullable part absent -- must NOT take the path with it
            ['/pricing', null,       'google', 'organic-search'],
            // the nullable part empty, which must group WITH absent, not beside it
            ['/pricing', '',         'google', 'organic-search'],
            // a different path, so the dimension has more than one value
            ['/docs',    'v=2',      'bing',   'organic-search'],
            // the pipeline could not resolve the source, but DID resolve the
            // medium -- the half-unresolved case, which renders as a label
            ['/docs',    null,       V2Event::UNRESOLVED, 'referral'],
        ];
    }

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Joined dimension fixture',
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
            'name'        => 'Joined dimension fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        $day = (int) date('Ymd');
        $ts  = time() * 1000000;

        foreach (self::fixtureRows() as $i => $row) {

            $event = Cubes::entityFor(self::PROPERTY);

            $event->setProperties([
                'id'         => 910000 + $i,
                'event_type' => 'page_view',
                'site_id'    => self::SITE,
                'visitor_id' => self::VISITOR,
                'session_id' => self::SESSION,
                'ts'         => $ts + $i,
                'yyyymmdd'   => $day,
                'page_path'  => $row[0],
                'page_query' => $row[1],
                'acq_source' => $row[2],
                'acq_medium' => $row[3],
            ]);

            if (!$event->create()) {
                throw new \RuntimeException(sprintf('seeding row %d failed: %s',
                    $i, owa_coreAPI::dbSingleton()->lastQueryError()));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::dropFixture();
        }
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            owa_coreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE)));

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

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

    private function manager(string $dimension)
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray($dimension));
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(25);

        return $rsm;
    }

    /** dimension value => pageViews, read from a real query. */
    private function group(string $dimension, string $key = 'value'): array
    {
        $rs = $this->manager($dimension)->getResults();

        $this->assertSame([], (array) $rs->errors, $dimension . ' did not resolve');

        $out = [];

        foreach ($rs->getResultsRows() as $row) {
            $out[(string) $row[$dimension][$key]] = (int) $row['pageViews']['value'];
        }

        return $out;
    }

    // ---- the expression itself -------------------------------------------

    public function testTheSqlCarriesAnAliasPlaceholderRatherThanAPrefix(): void
    {
        $sql = DimensionExpression::sql(['source', 'medium'], ' / ');

        $this->assertStringContainsString('%1$s.source', $sql);
        $this->assertStringContainsString('%1$s.medium', $sql);

        // The failure this replaced: event.CONCAT_WS(...), which MySQL reads as
        // a call to a function named CONCAT_WS in a schema named event.
        $this->assertStringStartsNotWith('%1$s.CONCAT', $sql);
    }

    public function testAPerGapSeparatorListIsHonoured(): void
    {
        $sql = DimensionExpression::sql(['host', 'page_path', 'page_query'], ['', '?']);

        $this->assertStringContainsString("CONCAT('', %1\$s.page_path)", $sql);
        $this->assertStringContainsString("CONCAT('?', %1\$s.page_query)", $sql);
    }

    /**
     * A percent sign in a separator is REFUSED, not escaped.
     *
     * The emitted SQL is sprintf'd more than once and only the first pass is
     * this class's. `=@` builds `LOCATE(%s, %s) > 0` with the operand as an
     * argument, so a surviving percent is read there as a third specifier
     * against two arguments and PHP 8 raises ArgumentCountError -- a report
     * that groups fine until somebody filters it with "contains".
     */
    public function testAPercentInASeparatorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DimensionExpression::sql(['a', 'b'], ' %s ');
    }

    /** And the alias substitution is unaffected for every legal separator. */
    public function testTheAliasSubstitutionSurvivesEveryDeclaredSeparator(): void
    {
        $declared = (array) (include OWA_DIR . 'modules/Base/config/dimensions.php')['dimensions'];

        foreach ($declared as $name => $d) {

            if (!isset($d['parts'])) {
                continue;
            }

            $sql = DimensionExpression::sql((array) $d['parts'], $d['separator'] ?? ' / ');

            $once = sprintf($sql, 'evt');

            $this->assertStringContainsString('evt.' . $d['parts'][0], $once, $name);

            // The second pass, which '=@' performs. It must not consume an
            // argument that is not there.
            $this->assertStringContainsString($once,
                sprintf('LOCATE(%s, %s) > 0', '?', $once), $name);
        }
    }

    public function testASeparatorCannotCloseTheStringLiteral(): void
    {
        $sql = DimensionExpression::sql(['a', 'b'], "o'clock");

        $this->assertStringContainsString("'o''clock'", $sql);
    }

    public static function refusedDeclarations(): array
    {
        return [
            'one part'            => [['source'], ' / '],
            'no parts'            => [[], ' / '],
            'not a column name'   => [['source', 'medium; DROP TABLE x'], ' / '],
            'an expression'       => [['source', 'CONCAT(a,b)'], ' / '],
            'too few separators'  => [['a', 'b', 'c'], ['']],
            'too many separators' => [['a', 'b'], ['', '?']],
        ];
    }

    /** @dataProvider refusedDeclarations */
    public function testABadDeclarationIsRefused(array $parts, $separator): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DimensionExpression::sql($parts, $separator);
    }

    // ---- what it does to real rows ---------------------------------------

    /**
     * THE WHOLE POINT. `/pricing` with no query string is `/pricing`, not
     * NULL -- so it groups with itself and not with every other page that
     * happens to be unparameterised.
     */
    public function testAnAbsentPartDoesNotEraseTheRestOfTheValue(): void
    {
        $rows = $this->group('pagePathPlusQuery');

        $this->assertSame(2, $rows['/pricing?plan=pro'] ?? null);

        // absent AND empty, together: both mean "no query string".
        $this->assertSame(2, $rows['/pricing'] ?? null,
            'a NULL query string and an empty one are one page, and neither is (not set)');

        $this->assertSame(1, $rows['/docs?v=2'] ?? null);
        $this->assertSame(1, $rows['/docs'] ?? null);

        $this->assertArrayNotHasKey('', $rows,
            'nothing collapsed to the empty value');
    }

    /** The separator goes with the part it precedes, or the value reads wrong. */
    public function testTheSeparatorDisappearsWithTheAbsentPart(): void
    {
        foreach (array_keys($this->group('pagePathPlusQuery')) as $value) {

            $this->assertStringEndsNotWith('?', $value,
                'a trailing separator means the gap was written for a part that was not');
        }
    }

    /** Grouping, not filtering: every row still counts exactly once. */
    public function testTheTotalIsUnchangedByTheJoin(): void
    {
        $expected = count(self::fixtureRows());

        foreach (['pagePath', 'pagePathPlusQuery', 'fullPageUrl',
                  'firstSourceMedium', 'firstSource'] as $dimension) {

            $rs = $this->manager($dimension)->getResults();

            $this->assertSame($expected, (int) $rs->aggregates['pageViews']['value'],
                $dimension . ' changed the total, so it is filtering rather than grouping');

            $this->assertSame($expected, array_sum($this->group($dimension)),
                $dimension . ' lost rows between the groups and the total');
        }
    }

    /**
     * A half-unresolved pair reads as a LABEL, not as blank.
     *
     * The sentinel is a control byte, so before formatDimensionValue() looked
     * inside a value this rendered as ` / ` -- a row that looks empty next to a
     * real count. The whole-value comparison could not see it because the value
     * is not the sentinel, it CONTAINS it.
     */
    public function testAnUnresolvedPartRendersAsItsLabelInsideTheJoinedValue(): void
    {
        $formatted = $this->group('firstSourceMedium', 'formatted_value');

        $this->assertSame(1, $formatted['(unknown) / referral'] ?? null);

        $this->assertArrayNotHasKey(' / ', $formatted,
            'the sentinel rendered as nothing, which is the bug the label exists to prevent');

        // The raw value keeps the sentinel: the label is a rendering, and a
        // filter or an export still has the byte to match on.
        $raw = $this->group('firstSourceMedium');

        $this->assertSame(1, $raw[V2Event::UNRESOLVED . ' / referral'] ?? null);
    }

    /** A dimension that is one column still renders the bare sentinel. */
    public function testTheSingleColumnSentinelIsUnaffected(): void
    {
        $formatted = $this->group('firstSource', 'formatted_value');

        $this->assertSame(1, $formatted['(unknown)'] ?? null);
    }

    /** Three parts with two different separators, which is why CONCAT_WS is not enough. */
    public function testAThreePartJoinUsesEachGapsOwnSeparator(): void
    {
        $rows = $this->group('fullPageUrl');

        // No host on the fixture rows, so the leading gap contributes nothing
        // and the value is the path -- the '' separator must not appear either.
        $this->assertSame(2, $rows['/pricing?plan=pro'] ?? null);
        $this->assertSame(2, $rows['/pricing'] ?? null);
    }

    /** It can be filtered and sorted on, not merely grouped by. */
    public function testAJoinedDimensionCanBeConstrainedAndSorted(): void
    {
        $rsm = $this->manager('pagePathPlusQuery');
        $rsm->setSort('pageViews', 'DESC');
        $rsm->setConstraints($rsm->parseConstraintsString('pagePathPlusQuery==/pricing?plan=pro'));

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors);

        $rows = $rs->getResultsRows();

        $this->assertCount(1, $rows, 'the constraint matched the joined value itself');
        $this->assertSame('/pricing?plan=pro', $rows[0]['pagePathPlusQuery']['value']);
        $this->assertSame(2, (int) $rows[0]['pageViews']['value']);
    }

    /**
     * Every declared joined dimension resolves in a real query.
     *
     * Read from the config file, so a seventh one added later is covered here
     * without anyone remembering to add it.
     */
    public function testEveryDeclaredJoinedDimensionResolves(): void
    {
        $declared = (array) (include OWA_DIR . 'modules/Base/config/dimensions.php')['dimensions'];

        $joined = array_keys(array_filter($declared, fn($d) => isset($d['parts'])));

        $this->assertGreaterThanOrEqual(6, count($joined));

        foreach ($joined as $name) {

            $rs = $this->manager($name)->getResults();

            $this->assertSame([], (array) $rs->errors, $name . ' did not resolve');

            $this->assertSame(count(self::fixtureRows()),
                (int) $rs->aggregates['pageViews']['value'],
                $name . ' changed the total');
        }
    }
}
