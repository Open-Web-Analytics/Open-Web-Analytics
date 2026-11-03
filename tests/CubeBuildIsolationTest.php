<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;

/**
 * When a build moves its connection to READ COMMITTED, and what it puts back.
 *
 * The server variables decide it, so these cover servers the suite does not
 * run against: a statement-based binlog, where READ COMMITTED refuses the
 * build's write, and MariaDB before 11.1, which names the level tx_isolation.
 * CubeBuildTest covers the build itself, against the configured database.
 */
final class CubeBuildIsolationTest extends TestCase
{
    public static function servers(): array
    {
        return [
            'MySQL 8 default, row binlog' => [
                ['transaction_isolation' => 'REPEATABLE-READ', 'log_bin' => 'ON', 'binlog_format' => 'ROW'],
                'REPEATABLE READ',
            ],
            'no binlog' => [
                ['transaction_isolation' => 'REPEATABLE-READ', 'log_bin' => 'OFF', 'binlog_format' => 'STATEMENT'],
                'REPEATABLE READ',
            ],
            'mixed binlog logs these writes by row' => [
                ['transaction_isolation' => 'REPEATABLE-READ', 'log_bin' => 'ON', 'binlog_format' => 'MIXED'],
                'REPEATABLE READ',
            ],
            'statement binlog refuses a READ COMMITTED write' => [
                ['transaction_isolation' => 'REPEATABLE-READ', 'log_bin' => 'ON', 'binlog_format' => 'STATEMENT'],
                null,
            ],
            'serializable also locks what it reads' => [
                ['transaction_isolation' => 'SERIALIZABLE', 'log_bin' => 'ON', 'binlog_format' => 'ROW'],
                'SERIALIZABLE',
            ],
            'already read committed' => [
                ['transaction_isolation' => 'READ-COMMITTED', 'log_bin' => 'ON', 'binlog_format' => 'ROW'],
                null,
            ],
            'read uncommitted takes no read locks' => [
                ['transaction_isolation' => 'READ-UNCOMMITTED', 'log_bin' => 'ON', 'binlog_format' => 'ROW'],
                null,
            ],
            'MariaDB before 11.1' => [
                ['tx_isolation' => 'REPEATABLE-READ', 'log_bin' => 'ON', 'binlog_format' => 'MIXED'],
                'REPEATABLE READ',
            ],
            'lowercase values' => [
                ['transaction_isolation' => 'repeatable-read', 'log_bin' => 'on', 'binlog_format' => 'statement'],
                null,
            ],
            'level unknown' => [
                ['log_bin' => 'ON', 'binlog_format' => 'ROW'],
                null,
            ],
        ];
    }

    /** @dataProvider servers */
    public function testTheLevelToPutBack(array $variables, ?string $expected): void
    {
        $this->assertSame($expected, Builder::levelToRestore($variables));
    }
}

?>
