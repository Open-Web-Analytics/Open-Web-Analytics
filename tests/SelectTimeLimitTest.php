<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Report queries run under a statement time limit, and the session gets its own
 * limit back afterwards (Db::withSelectTimeLimit()).
 */
final class SelectTimeLimitTest extends TestCase
{
    private $db;
    private $var;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        $this->db  = \OWA\Core\CoreAPI::dbSingleton();
        $version   = (string) $this->db->get_row('SELECT VERSION() AS v')['v'];
        $this->var = stripos($version, 'mariadb') !== false ? 'max_statement_time' : 'max_execution_time';
    }

    private function current()
    {
        return $this->db->get_row(sprintf('SELECT @@SESSION.%s AS v', $this->var))['v'] + 0;
    }

    public function testTheLimitHoldsInsideAndIsRestoredAfter(): void
    {
        $before = $this->current();

        $inside = $this->db->withSelectTimeLimit(7, function () {
            return $this->current();
        });

        $this->assertEquals($this->var === 'max_execution_time' ? 7000 : 7, $inside);
        $this->assertEquals($before, $this->current());
    }

    public function testItIsRestoredWhenTheQueryThrows(): void
    {
        $before = $this->current();

        try {
            $this->db->withSelectTimeLimit(7, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
        }

        $this->assertEquals($before, $this->current());
    }

    public function testAQueryBeingBuiltSurvivesTheLimitBeingSet(): void
    {
        // The builder is shared: a query another test built and never ran (an
        // ORDER BY, say) would otherwise ride along with this one.
        $params = new ReflectionProperty($this->db, '_sqlParams');
        $params->setValue($this->db, array());

        $this->db->selectFrom('owa_site');
        $this->db->selectColumn('COUNT(*) AS n');

        $rows = $this->db->withSelectTimeLimit(30, function () {
            return $this->db->getAllRows();
        });

        $this->assertIsArray($rows);
        $this->assertArrayHasKey('n', (array) $rows[0]);
    }
}
