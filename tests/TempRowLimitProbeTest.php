<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * TEMPORARY. Measures what the CI server will actually accept, and is deleted
 * once the number is known.
 *
 * MySQL 8.4 here refuses at 65,535 (the row definition limit) and MySQL 8.0 on
 * CI refuses at 8,126 (InnoDB's on-page limit), with the same row format and
 * the same charset. The second is far tighter and is what most installations
 * run, so the cube's real capacity for custom dimensions has to be measured
 * there rather than calculated here.
 *
 * It fails on purpose, because a passing test prints nothing.
 */
final class TempRowLimitProbeTest extends TestCase
{
    private function probe(string $label, array $dropFirst = []): string
    {
        $db = owa_coreAPI::dbSingleton();
        $t  = 'owa_probe_' . bin2hex(random_bytes(3));

        $entity = owa_coreAPI::entityFactory('base.event');
        $entity->setTableName(substr($t, 4));
        $entity->createTable();
        $db->removePartitioning($t);

        if ($dropFirst) {
            $db->alterColumnsRebuilding($t, [], $dropFirst);
        }

        // One VARCHAR(64) plus one BIGINT is what a user-scoped dimension costs.
        $n = 0;

        while ($n < 60) {
            $ok = $db->alterColumnsRebuilding($t, [
                'cd_p' . $n         => 'VARCHAR(64) NULL',
                'cd_p' . $n . '_ts' => 'BIGINT NULL',
            ]);

            if (!$ok) {
                break;
            }

            $n++;
        }

        $error = $db->lastQueryError();
        $db->query("DROP TABLE IF EXISTS $t");

        return sprintf("  %-34s %2d dimensions  [%s]", $label, $n,
            substr(preg_replace('/\s+/', ' ', $error), 0, 90));
    }

    public function testHowManyDimensionsThisServerTakes(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        $db = owa_coreAPI::dbSingleton();

        $lines = [
            'version ' . $db->get_row('SELECT VERSION() v')['v']
                . ', page ' . $db->get_row('SELECT @@innodb_page_size p')['p']
                . ', default row format ' . $db->get_row('SELECT @@innodb_default_row_format f')['f'],
            '',
            $this->probe('cube as it is'),
            $this->probe('without raw_ua', ['raw_ua']),
            $this->probe('without the 4 query/ua columns',
                ['raw_ua', 'page_query', 'landing_page_query', 'referer_query']),
            $this->probe('without those 4 + the 4 locations',
                ['raw_ua', 'page_query', 'landing_page_query', 'referer_query',
                 'page_location', 'landing_page_location', 'referer_url', 'target_url']),
        ];

        $this->fail("ROW LIMIT PROBE\n" . implode("\n", $lines));
    }
}
