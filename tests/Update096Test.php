<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * site_transaction leaves every cube and stays on raw. down() puts it back on
 * every cube as Update057 made it.
 */
final class Update096Test extends TestCase
{
    const PROPERTY = 7788000000000002;

    /** @var \OWA\Module\Base\Update\Update096|null */
    private $update;

    public static function setUpBeforeClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropCube();

        if ( ! \OWA\Module\Base\Classes\Cube\Cubes::create( self::PROPERTY ) ) {
            throw new \RuntimeException( 'creating the fixture cube failed' );
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ( owa_test_db_available() ) {
            self::dropCube();
        }
    }

    private static function dropCube(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY ) ) );
    }

    private function cube(): string
    {
        return \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY );
    }

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->update = new \OWA\Module\Base\Update\Update096();
    }

    protected function tearDown(): void
    {
        if ( $this->update ) {
            $this->update->up();
        }
    }

    public function testAFreshCubeHasNoneAndRawKeepsIt(): void
    {
        $this->assertSame( [], $this->indexColumns( $this->cube() ) );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns() );
    }

    public function testDownAddsItToEveryCubeAndUpTakesItOff(): void
    {
        $this->assertTrue( $this->update->down() );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns( $this->cube() ) );
        $this->assertTrue( $this->update->down(), 'run again, it finds it there' );

        $this->assertTrue( $this->update->up() );
        $this->assertSame( [], $this->indexColumns( $this->cube() ) );
        $this->assertTrue( $this->update->up(), 'run again, it finds nothing to drop' );

        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns(), 'raw is not touched' );
    }

    /** A cube made from the entity matches what up() leaves, so a build's staging table matches its cube. */
    public function testTheCubeEntityDeclaresNoneAndRawDoes(): void
    {
        $cube = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        $this->assertArrayNotHasKey( 'site_transaction', $cube->getCompositeIndexes() );
        $this->assertArrayHasKey( 'site_transaction',
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getCompositeIndexes() );
    }

    private function indexColumns( ?string $table = null ): array
    {
        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = 'site_transaction' ORDER BY SEQ_IN_INDEX",
            $table ?? \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ) );

        return array_map( fn( $r ) => ( (array) $r )['c'], $rows );
    }
}
