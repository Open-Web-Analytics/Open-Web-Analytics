<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** owa_event_raw gains the (site_id, ts) index realtime reads through; cubes do not. */
final class Update064Test extends TestCase
{
    const PROPERTY = 7790000000000001;

    /** @var \OWA\Module\Base\Update\Update064 */
    private $update;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->update = new \OWA\Module\Base\Update\Update064();
    }

    protected function tearDown(): void
    {
        if ( $this->update ) {
            $this->update->up();
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY ) ) );
    }

    public function testUpAndDownAreRepeatable(): void
    {
        $this->assertTrue( $this->update->down() );
        $this->assertTrue( $this->update->down() );
        $this->assertSame( [], $this->indexColumns() );

        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );
        $this->assertSame( [ 'site_id', 'ts' ], $this->indexColumns() );
    }

    /** A fresh install declares the same index, by the same name. */
    public function testTheEntityDeclaresTheSameIndex(): void
    {
        $this->assertSame( [ 'site_id', 'ts' ],
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getCompositeIndexes()['site_ts'] ?? null );
    }

    /** Nothing reads a cube by time, so a cube -- fresh or not -- has no site_ts. */
    public function testACubeHasNoSiteTs(): void
    {
        $this->assertArrayNotHasKey( 'site_ts',
            \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getCompositeIndexes() );

        $this->assertTrue( \OWA\Module\Base\Classes\Cube\Cubes::create( self::PROPERTY ) );
        $this->assertSame( [], $this->indexColumns( \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY ) ) );
    }

    private function indexColumns( ?string $table = null ): array
    {
        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = 'site_ts' ORDER BY SEQ_IN_INDEX",
            $table ?? \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ) );

        return array_map( fn( $r ) => ( (array) $r )['c'], $rows );
    }
}
