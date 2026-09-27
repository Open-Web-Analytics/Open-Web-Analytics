<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** owa_event_raw gains the (site_id, transaction_id) index the dedupe lookup uses. */
final class Update057Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update057 */
    private $update;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->update = new \OWA\Module\Base\Update\Update057();
    }

    protected function tearDown(): void
    {
        if ( $this->update ) {
            $this->update->up();
        }
    }

    public function testUpAddsTheIndexOnBothColumnsInOrder(): void
    {
        $this->assertTrue( $this->update->down() );
        $this->assertSame( [], $this->indexColumns() );

        $this->assertTrue( $this->update->up() );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns() );
    }

    public function testUpAndDownAreRepeatable(): void
    {
        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns() );

        $this->assertTrue( $this->update->down() );
        $this->assertTrue( $this->update->down() );
        $this->assertSame( [], $this->indexColumns() );
    }

    /** A fresh install declares the same index, by the same name. */
    public function testTheEntityDeclaresTheSameIndex(): void
    {
        $this->assertSame( [ 'site_id', 'transaction_id' ],
            owa_coreAPI::entityFactory( 'base.event_raw' )->getCompositeIndexes()['site_transaction'] ?? null );
    }

    private function indexColumns(): array
    {
        $rows = (array) owa_coreAPI::dbSingleton()->get_results( sprintf(
            "SELECT COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = 'site_transaction' ORDER BY SEQ_IN_INDEX",
            owa_coreAPI::entityFactory( 'base.event_raw' )->getTableName() ) );

        return array_map( fn( $r ) => ( (array) $r )['c'], $rows );
    }
}
