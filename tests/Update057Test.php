<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_event_raw and every cube gain the (site_id, transaction_id) index the
 * dedupe lookup uses.
 */
final class Update057Test extends TestCase
{
    const PROPERTY = 7788000000000001;

    /** @var \OWA\Module\Base\Update\Update057 */
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

    public function testEveryCubeGetsItAndLosesIt(): void
    {
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns( $this->cube() ),
            'a fresh cube has it from the entity' );

        $this->assertTrue( $this->update->down() );
        $this->assertSame( [], $this->indexColumns( $this->cube() ) );

        $this->assertTrue( $this->update->up() );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns( $this->cube() ) );
    }

    /**
     * A ROLLBACK THROUGH Update050 PUTS IT BACK WHOLE.
     *
     * 050's down() drops transaction_id from the cube. With the index still
     * there, the server shrinks it to (site_id) and nothing on the way up
     * widens it again. This down() runs first and removes it, so the column
     * drops cleanly and this up() adds it whole.
     */
    public function testARollbackThroughTheColumnDropRestoresTheWholeIndex(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertTrue( $this->update->down() );

        $this->assertTrue( $db->alterColumnsRebuilding( $this->cube(), [], [ 'transaction_id' ] ) );
        $this->assertTrue( $db->alterColumnsRebuilding( $this->cube(),
            [ 'transaction_id' => 'VARCHAR(255) NULL' ] ) );

        $this->assertTrue( $this->update->up() );
        $this->assertSame( [ 'site_id', 'transaction_id' ], $this->indexColumns( $this->cube() ) );
    }

    /** A fresh install declares the same index, by the same name. */
    public function testTheEntityDeclaresTheSameIndex(): void
    {
        $this->assertSame( [ 'site_id', 'transaction_id' ],
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getCompositeIndexes()['site_transaction'] ?? null );
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
