<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * screen_resolution: the column on raw and every cube, and what ingest stores.
 */
final class Update058Test extends IngestionTestCase
{
    const FIXTURE_PROPERTY = 7775000000000098;

    private $site;
    private $update;
    private $createdCube = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
        $this->update = new \OWA\Module\Base\Update\Update058();

        // CI's database has no cube until a build makes one, and the cube half
        // is the half that can go wrong (see the CubeColumn trait).
        if ( ! \OWA\Module\Base\Classes\Cube\Cubes::allTables() ) {
            $this->assertTrue( (bool) \OWA\Module\Base\Classes\Cube\Cubes::create( self::FIXTURE_PROPERTY ),
                'creating a fixture cube' );
            $this->createdCube = true;
        }
    }

    protected function tearDown(): void
    {
        // Whatever a case did, leave the column where the code expects it.
        $this->update->up();

        if ( $this->createdCube ) {
            foreach ( array( '', '_rebuild', '_computed' ) as $suffix ) {
                owa_coreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::FIXTURE_PROPERTY ), $suffix ) );
            }
        }

        parent::tearDown();
    }

    public function testTheUpdateAndTheEntityAgree(): void
    {
        $this->assertSame( 58, $this->update->schema_version );
        $this->assertContains( 'screen_resolution',
            owa_coreAPI::entityFactory( 'base.event_raw' )->getColumns() );
    }

    public function testUpAddsTheColumnToRawAndEveryCube(): void
    {
        $this->assertTrue( $this->update->up() );

        foreach ( $this->tables() as $table ) {
            $this->assertTrue( $this->hasColumn( $table ), "$table.screen_resolution" );
        }
    }

    public function testUpIsIdempotent(): void
    {
        $this->assertTrue( $this->update->up() );
        $this->assertTrue( $this->update->up() );
    }

    public function testDownRemovesItEverywhereAndUpPutsItBack(): void
    {
        $this->assertTrue( $this->update->down() );
        $this->assertTrue( $this->update->down(), 'down is idempotent too' );

        foreach ( $this->tables() as $table ) {
            $this->assertFalse( $this->hasColumn( $table ), "$table.screen_resolution after down" );
        }

        $this->assertTrue( $this->update->up() );

        foreach ( $this->tables() as $table ) {
            $this->assertTrue( $this->hasColumn( $table ), "$table.screen_resolution after up" );
        }
    }

    /**
     * @dataProvider sentValues
     */
    public function testIngestStoresAWellFormedSizeAndDropsAnythingElse( $sent, $stored ): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent( 'page_view', [
            'site_id'           => $this->site,
            'visitor_id'        => $visitor,
            'session_id'        => $session,
            'page_url'          => 'https://owa-test-site/v2/screen',
            'page_location'     => 'https://owa-test-site/v2/screen',
            'screen_resolution' => $sent,
        ] );

        $rows = array_map( fn( $r ) => (array) $r, (array) owa_coreAPI::dbSingleton()->get_results( sprintf(
            "SELECT id, screen_resolution FROM %s WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d AND event_type = 'page_view'",
            $this->rawTable(), $this->site, (int) $visitor, (int) $session ) ) );

        foreach ( $rows as $row ) {
            $this->trackForCleanup( 'base.event_raw', (string) $row['id'], 'id' );
        }

        $this->assertCount( 1, $rows );
        $this->assertSame( $stored, $rows[0]['screen_resolution'] );
    }

    public static function sentValues(): array
    {
        return [
            'a screen'             => [ '1920x1080', '1920x1080' ],
            'upper case, spaced'   => [ ' 390X844 ', '390x844' ],
            'a zero side'          => [ '0x1080', null ],
            'not a size'           => [ 'wide', null ],
            'a unit on the end'    => [ '1920x1080px', null ],
            'six digits'           => [ '100000x1080', null ],
        ];
    }

    /* ---------------- helpers ---------------- */

    private function rawTable(): string
    {
        return owa_coreAPI::entityFactory( 'base.event_raw' )->getTableName();
    }

    /** @return string[] raw and every cube table that exists */
    private function tables(): array
    {
        $tables = array_merge( [ $this->rawTable() ],
            \OWA\Module\Base\Classes\Cube\Cubes::allTables() );

        $this->assertGreaterThan( 1, count( $tables ), 'no cube table exists, so the cubes go untested' );

        return $tables;
    }

    private function hasColumn( string $table ): bool
    {
        return (bool) owa_coreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE 'screen_resolution'", $table ) );
    }
}
