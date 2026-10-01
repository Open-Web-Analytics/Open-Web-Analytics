<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update066: prior_touch_* on raw, last_touch_* on the visitor store,
 * attributed_* on every cube; down() takes exactly those away.
 */
final class Update066Test extends TestCase
{
    const PROPERTY = 7792000000000001;

    /** @var \OWA\Module\Base\Update\Update066 */
    private $update;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $this->update = new \OWA\Module\Base\Update\Update066();
    }

    protected function tearDown(): void
    {
        if ( $this->update ) {
            $this->update->up();
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY ) ) );
    }

    /** @return string[] the columns $table has whose names start with $prefix, in table order */
    private function columns( string $table, string $prefix ): array
    {
        $out = [];

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( "SHOW COLUMNS FROM $table" ) as $row ) {
            if ( strpos( $row['Field'], $prefix ) === 0 ) {
                $out[] = $row['Field'];
            }
        }

        return $out;
    }

    public function testTheUpdateAndTheEntitiesAgree(): void
    {
        $this->assertSame( 66, $this->update->schema_version );
        $this->assertTrue( $this->update->is_cli_mode_required, 'it backfills historical raw rows' );

        $this->assertSame( array_keys( \OWA\Module\Base\Update\Update066::RAW ),
            array_values( array_filter( \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns(),
                fn ( $c ) => strpos( $c, 'prior_touch_' ) === 0 ) ) );
        $this->assertSame( array_keys( \OWA\Module\Base\Update\Update066::VISITOR ),
            array_values( array_filter( \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getColumns(),
                fn ( $c ) => strpos( $c, 'last_touch_' ) === 0 ) ) );
        $this->assertSame( \OWA\Module\Base\Update\Update066::CUBE,
            array_values( array_filter( \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getColumns(),
                fn ( $c ) => strpos( $c, 'attributed_' ) === 0 ) ) );
    }

    public function testUpAndDownAreRepeatable(): void
    {
        $this->assertTrue( \OWA\Module\Base\Classes\Cube\Cubes::create( self::PROPERTY ) );

        $raw   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $store = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();
        $cube  = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::PROPERTY );

        $this->assertTrue( $this->update->down() );
        $this->assertTrue( $this->update->down() );

        $this->assertSame( [], $this->columns( $raw, 'prior_touch_' ) );
        $this->assertSame( [], $this->columns( $store, 'last_touch_' ) );
        $this->assertSame( [], $this->columns( $cube, 'attributed_' ) );

        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );

        $this->assertSame( array_keys( \OWA\Module\Base\Update\Update066::RAW ), $this->columns( $raw, 'prior_touch_' ) );
        $this->assertSame( array_keys( \OWA\Module\Base\Update\Update066::VISITOR ), $this->columns( $store, 'last_touch_' ) );
        $this->assertSame( \OWA\Module\Base\Update\Update066::CUBE, $this->columns( $cube, 'attributed_' ) );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $mariadb = stripos( (string) ( $db->get_row( 'SELECT VERSION() AS v' )['v'] ?? '' ), 'mariadb' ) !== false;

        // MariaDB reports no instant-column state (CubeInstantColumnsTest), so there it is unknown.
        $this->assertSame( $mariadb ? null : false, $db->hasInstantColumns( $cube ),
            'added by a rebuild, so the cube still swaps' );
    }
}
