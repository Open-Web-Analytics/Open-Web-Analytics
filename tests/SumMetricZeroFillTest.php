<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A sum over rows that hold nothing is 0, not NULL.
 *
 * A purchase with no tax holds NULL in `tax`, and SUM over NULL alone is NULL
 * -- which the Transactions report showed as a blank cell beside zeros.
 * Run against the metrics' own expressions, on a scratch table aliased as the
 * cube is.
 */
final class SumMetricZeroFillTest extends TestCase
{
    private string $table = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('runs SQL');
        }

        $this->table = 'owa_test_sumzero_' . bin2hex(random_bytes(3));

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf('CREATE TABLE %s (event_type VARCHAR(32), tax BIGINT NULL, engagement_msec BIGINT NULL)',
            $this->table));
        $db->query(sprintf("INSERT INTO %s VALUES ('purchase', NULL, NULL)", $this->table));
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . $this->table);
        }
    }

    private function value(string $metric)
    {
        $implementations = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMetricClasses($metric);
        $implementation  = reset($implementations);
        $select = \OWA\Core\CoreAPI::metricFactory($implementation['class'], $implementation['params'] ?? [])
            ->getSelect();

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf('SELECT %s AS v FROM %s AS event', $select[0] ?? $select, $this->table));

        return $row['v'] ?? null;
    }

    /** A conditioned sum: the purchase matches, and its tax is NULL. */
    public function testAPurchaseWithNoTaxSumsToZero(): void
    {
        $this->assertSame('0', (string) $this->value('taxRevenue'));
        $this->assertNotNull($this->value('taxRevenue'));
    }

    /** An unconditioned sum over NULLs alone. */
    public function testAnUnconditionedSumOfNothingIsZero(): void
    {
        $this->assertNotNull($this->value('totalEngagementTime'));
        $this->assertSame('0', (string) $this->value('totalEngagementTime'));
    }
}
