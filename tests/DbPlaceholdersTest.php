<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Db::placeholders(): the IN list of bound values, one "?" per value.
 */
final class DbPlaceholdersTest extends TestCase
{
    public function testOnePlaceholderPerValue(): void
    {
        $this->assertSame('?', \OWA\Core\Db::placeholders(1));
        $this->assertSame('?, ?, ?', \OWA\Core\Db::placeholders(3));
    }

    public function testTheCountMatchesWhatTheDriverCounts(): void
    {
        $sql = sprintf('SELECT 1 FROM t WHERE a IN (%s)', \OWA\Core\Db::placeholders(7));

        $this->assertSame(7, \OWA\Core\Db\Pdo::countPlaceholders($sql));
    }

    public function testAnEmptyListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \OWA\Core\Db::placeholders(0);
    }
}
