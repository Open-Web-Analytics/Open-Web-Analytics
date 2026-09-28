<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\V1Tables;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * The v1 table list, and the frozen schema the migration is tested against.
 */
final class V1TablesTest extends TestCase
{
    public function testTwentyTablesFactsThenDimensions(): void
    {
        $this->assertCount(20, V1Tables::all());
        $this->assertSame(array_merge(V1Tables::FACTS, V1Tables::DIMENSIONS), V1Tables::all());
        $this->assertSame([], array_intersect(V1Tables::FACTS, V1Tables::DIMENSIONS));
    }

    public function testANameOutsideTheListIsRefused(): void
    {
        $this->assertSame('owa_request', V1Tables::name('request'));
        $this->assertSame('x_session', V1Tables::name('session', 'x_'));

        $this->expectException(InvalidArgumentException::class);
        V1Tables::name('site');
    }

    /** No table v2 keeps may be on the list the drop command removes. */
    public function testNoTableV2KeepsIsAV1Table(): void
    {
        foreach ((array) \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities as $entity) {
            if (in_array($entity, V1Tables::all(), true)) {
                continue; // the v1 entity classes themselves, until they are deleted
            }

            $table = \OWA\Core\CoreAPI::entityFactory('base.' . $entity)->getTableName();

            $this->assertNotContains(substr($table, strlen('owa_')), V1Tables::all(), $table);
        }

        foreach (['event_raw', 'visitor_acquisition', 'site', 'user', 'setting', 'goal_event'] as $kept) {
            $this->assertNotContains($kept, V1Tables::all());
        }
    }

    public function testTheFixtureDefinesExactlyTheV1Tables(): void
    {
        $tables = array_map(function ($statement) {
            preg_match('/^CREATE TABLE `owa_v1fx_([a-z_]+)`/', $statement, $m);
            return $m[1] ?? $statement;
        }, V1Schema::statements());

        $this->assertSame(V1Tables::all(), $tables);
    }

    public function testTheFixtureLoadsUnderItsOwnPrefixAndDrops(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        try {
            V1Schema::load();
            $this->assertSame(V1Tables::all(), V1Tables::present($db, V1Schema::PREFIX));
            $this->assertContains('referer_id', V1Schema::columns('request'));

            V1Schema::asVarcharKeys();
            $type = (array) $db->get_row("SHOW COLUMNS FROM owa_v1fx_request LIKE 'ua_id'");
            $this->assertStringStartsWith('varchar', strtolower((string) $type['Type']));
        } finally {
            V1Schema::drop();
        }

        $this->assertSame([], V1Tables::present($db, V1Schema::PREFIX));
    }
}
