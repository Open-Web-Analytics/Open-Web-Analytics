<?php

use PHPUnit\Framework\TestCase;

/**
 * The PDO driver must generate byte-identical SQL to the mysqli driver.
 *
 * WHY THIS IS THE TEST THAT MATTERS
 * ---------------------------------
 * A driver looks like a transport, but OWA's drivers also supply the one
 * function that the SQL BUILDER calls while composing statements: prepare().
 * Every value and every identifier the builder interpolates passes through it,
 * and the builder adds the surrounding quotes itself:
 *
 *     sprintf( "%s = '%s'", $this->prepare( $name ), $this->prepare( $value ) )
 *
 * mysqli_real_escape_string() escapes WITHOUT quotes; PDO::quote() ADDS them.
 * A PDO prepare() that returns quote() verbatim yields `col = ''value''` --
 * syntactically broken, on every query, everywhere. So driver equivalence
 * cannot be argued from "both run SQL"; it has to be measured on the generated
 * text.
 *
 * That matters most for REPORTING, which is where OWA builds SQL dynamically
 * rather than from literals: the select list, joins, group-bys and constraints
 * are assembled at runtime from the metric and dimension registry, and
 * calculated metrics contribute their own SQL expressions. None of that is
 * visible to a test that only checks a driver can SELECT 1, which is why the
 * cases below drive the builder the way a report does.
 *
 * Skips without a database, like every other DB-backed test here.
 */
final class DbDriverSqlParityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped('No database available.');
        }

        if ( ! extension_loaded('pdo_mysql') ) {
            $this->markTestSkipped('pdo_mysql not loaded.');
        }
    }

    /** A driver of the given type, connected with the install's own credentials. */
    private function driver(string $class): \OWA\Core\Db
    {
        $db = new $class(
            owa_coreAPI::getSetting('base', 'db_host'),
            owa_coreAPI::getSetting('base', 'db_port'),
            owa_coreAPI::getSetting('base', 'db_name'),
            owa_coreAPI::getSetting('base', 'db_user'),
            owa_coreAPI::getSetting('base', 'db_password'),
            true,
            false
        );

        $this->assertTrue($db->connect(), 'driver failed to connect: ' . $class);

        return $db;
    }

    private function drivers(): array
    {
        return [
            'mysqli' => $this->driver(\OWA\Core\Db\Mysql::class),
            'pdo'    => $this->driver(\OWA\Core\Db\Pdo::class),
        ];
    }

    /**
     * Run the same builder programme against both drivers and return the SQL
     * each produced. The callable receives the driver, so it can drive exactly
     * the builder calls a report would.
     */
    private function bothSql(callable $build): array
    {
        $out = [];

        foreach ($this->drivers() as $name => $db) {
            $build($db);
            $out[$name] = $db->generateSelectQuerySql();
            $db->close();
        }

        return $out;
    }

    private function assertSameSql(callable $build, string $because): void
    {
        $sql = $this->bothSql($build);

        $this->assertNotSame('', trim($sql['mysqli']), 'the builder produced no SQL');
        $this->assertSame($sql['mysqli'], $sql['pdo'], $because);
    }

    public function testEscapingProducesNoSurroundingQuotes(): void
    {
        // The trap, isolated: this is what the builder interpolates BETWEEN its
        // own quotes, so a leading/trailing quote here corrupts every statement.
        foreach ($this->drivers() as $name => $db) {
            $escaped = $db->prepare("O'Brien");

            $this->assertStringStartsNotWith("'", $escaped, "$name: prepare() added a leading quote");
            $this->assertStringEndsNotWith("'", $escaped, "$name: prepare() added a trailing quote");

            $db->close();
        }
    }

    public function testASimpleConstrainedSelectIsIdentical(): void
    {
        $this->assertSameSql(function ($db) {
            $db->selectFrom('owa_request', 'r');
            $db->selectColumn('id');
            $db->where('site_id', 'abc123');
        }, 'a basic select differs between drivers');
    }

    /** Values that actually exercise the escaper. */
    public function testValuesNeedingEscapesAreIdentical(): void
    {
        foreach (["O'Brien", 'a"b', "back\\slash", "line\nbreak", "semi;colon", "100% \x00null"] as $hostile) {
            $this->assertSameSql(function ($db) use ($hostile) {
                $db->selectFrom('owa_request', 'r');
                $db->selectColumn('id');
                $db->where('page_url', $hostile);
            }, 'escaping differs for: ' . json_encode($hostile));
        }
    }

    public function testEveryConstraintOperatorIsIdentical(): void
    {
        foreach (['=', '!=', '>', '<', '>=', '<=', 'LIKE'] as $op) {
            $this->assertSameSql(function ($db) use ($op) {
                $db->selectFrom('owa_request', 'r');
                $db->selectColumn('id');
                $db->where('page_url', "va'lue", $op);
            }, "operator $op differs between drivers");
        }
    }

    public function testABetweenConstraintIsIdentical(): void
    {
        $this->assertSameSql(function ($db) {
            $db->selectFrom('owa_request', 'r');
            $db->selectColumn('id');
            $db->where('yyyymmdd', ['start' => '20260101', 'end' => '20260131'], 'BETWEEN');
        }, 'a BETWEEN constraint differs between drivers');
    }

    /**
     * A report-shaped query: joins, group-by, having, order, limit and offset all
     * at once. This is the construction the reporting layer actually performs,
     * and each clause passes its own values through prepare().
     */
    public function testAReportShapedQueryIsIdentical(): void
    {
        $this->assertSameSql(function ($db) {
            $db->selectFrom('owa_request', 'r');
            $db->selectColumn('COUNT(*)', 'visits');
            $db->selectColumn('r.page_url', 'pagePath');
            $db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'd', 'r.document_id', 'd.id');
            $db->where('r.site_id', "site'1");
            $db->where('r.yyyymmdd', ['start' => '20260101', 'end' => '20260131'], 'BETWEEN');
            $db->groupBy('r.page_url');
            $db->having('visits', 5, '>');
            $db->orderBy('visits', OWA_SQL_DESCENDING);
            $db->limit(25);
            $db->offset(50);
        }, 'a report-shaped query differs between drivers');
    }

    /**
     * Calculated metrics contribute their own SQL expression to the select list
     * rather than a bare column, so they are a distinct construction path from
     * the clauses above and are pinned separately.
     */
    public function testCalculatedMetricSelectExpressionsAreIdentical(): void
    {
        // Fully qualified on purpose: metricFactory() with a BARE name resolves
        // through getMetricClasses(), which returns an ARRAY when a metric is
        // registered by more than one module, and moduleSpecificFactory() then
        // explodes it -- a TypeError. Not this test's business to fix, but it is
        // why these are 'base.x' rather than 'x'.
        $metrics = ['base.bounceRate', 'base.actionsPerVisit', 'base.revenuePerVisit', 'base.ecommerceConversionRate'];
        $checked = 0;

        foreach ($metrics as $name) {

            try {
                $metric = owa_coreAPI::metricFactory($name);
            } catch (\Throwable $e) {
                // Not registered in this build; the count assertion below is what
                // stops the whole test quietly passing on an empty list.
                continue;
            }

            if ( ! is_object($metric) || ! method_exists($metric, 'getSelect') ) {
                continue;
            }

            $select = $metric->getSelect();

            if ( ! $select ) {
                continue;
            }

            $checked++;

            $this->assertSameSql(function ($db) use ($select) {
                $db->selectFrom('owa_session', 's');
                $db->selectColumn($select);
                $db->where('s.site_id', "site'1");
                $db->groupBy('s.site_id');
            }, "calculated metric $name produces different SQL between drivers");
        }

        $this->assertGreaterThan(0, $checked,
            'no calculated metric was exercised -- the registry lookup must have failed, '
            . 'so this test was proving nothing');
    }

    /** Insert, update and delete build their values through prepare() too. */
    public function testWriteStatementsAreIdentical(): void
    {
        $sql = [];

        foreach ($this->drivers() as $name => $db) {
            $db->insertInto('owa_request');
            $db->set('id', 12345);
            $db->set('page_url', "O'Brien's page");
            $sql[$name] = $db->_insertQuery();
            $db->close();
        }

        $this->assertSame($sql['mysqli'], $sql['pdo'], 'INSERT differs between drivers');
    }

    /** The results themselves must match, not merely the statements. */
    public function testBothDriversReturnTheSameRows(): void
    {
        $sql = "SELECT 1 AS one, 'O''Brien' AS name";
        $rows = [];

        foreach ($this->drivers() as $name => $db) {
            $rows[$name] = $db->get_results($sql);
            $db->close();
        }

        $this->assertSame($rows['mysqli'], $rows['pdo'], 'the drivers returned different rows');
        $this->assertNotNull($rows['pdo']);
    }

    /** No rows must read as null from both, not [] from one and null from the other. */
    public function testNoRowsIsNullFromBothDrivers(): void
    {
        foreach ($this->drivers() as $name => $db) {
            $this->assertNull($db->get_results('SELECT 1 AS x FROM DUAL WHERE 1 = 0'),
                "$name: an empty result must be null");
            $this->assertNull($db->get_row('SELECT 1 AS x FROM DUAL WHERE 1 = 0'),
                "$name: an empty row must be null");
            $db->close();
        }
    }

    /** A failed statement must be falsy, not an exception, from both. */
    public function testAFailedQueryIsFalsyFromBothDrivers(): void
    {
        foreach ($this->drivers() as $name => $db) {
            $this->assertFalse((bool) $db->query('SELECT * FROM a_table_that_does_not_exist_here'),
                "$name: a failed query must return falsy rather than throw");
            $this->assertNull($db->get_row('SELECT * FROM a_table_that_does_not_exist_here'),
                "$name: get_row on a failed query must be null");
            $db->close();
        }
    }
}
