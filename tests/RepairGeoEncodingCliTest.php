<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * `php cli.php cmd=repair-geo-encoding`, against a real database.
 *
 * GeoEncodingRepairTest covers the decision of what to rewrite, exhaustively and
 * without a database. This covers everything around it that only exists when
 * there are rows: the scan, the row limit, the writes, and the dry run.
 *
 * It matters more than a command's tests usually do, because this one rewrites
 * stored data on a judgement about what that data used to be, and there is no
 * undo. The cases asserting that a row was NOT touched are the point.
 *
 * Skips where no database is reachable, and runs for real in the CI isolation
 * sweep, which provisions a scratch install and runs every test file in its own
 * process against both the PDO and mysqli drivers.
 */
final class RepairGeoEncodingCliTest extends CliControllerTestCase
{
    const CLASS_NAME = \OWA\Module\Base\Controller\RepairGeoEncodingCli::class;
    const FILE       = 'Controller/RepairGeoEncodingCli.php';

    /**
     * A location_dim row carrying the given names, tracked for cleanup.
     *
     * The id is generated the way LocationHandlers generates it, from country
     * and city, so the fixture is shaped like a row the tracker would have
     * written rather than like something only a test would produce.
     *
     * @return string the row id
     */
    private function makeLocation( string $country, string $state, string $city ): string
    {
        $e  = \OWA\Core\CoreAPI::entityFactory( 'base.location_dim' );
        $id = $e->generateId( $country . $city );

        $e->set( 'id', $id );
        $e->set( 'country', $country );
        $e->set( 'country_code', 'zz' );
        $e->set( 'state', $state );
        $e->set( 'city', $city );
        $e->set( 'latitude', '0' );
        $e->set( 'longitude', '0' );
        $e->create();

        $this->trackForCleanup( 'base.location_dim', $id, 'id' );

        return (string) $id;
    }

    /** Read one row back from the database, not from a cached entity. */
    private function reload( string $id ): array
    {
        $e = \OWA\Core\CoreAPI::entityFactory( 'base.location_dim' );
        $e->getByPk( 'id', $id );

        return array(
            'id'      => (string) $e->get( 'id' ),
            'country' => (string) $e->get( 'country' ),
            'state'   => (string) $e->get( 'state' ),
            'city'    => (string) $e->get( 'city' ),
        );
    }

    /** The limit is passed high so a fixture row is never outside the scan. */
    private function runRepair( array $params = array() ): array
    {
        return $this->runCommand( self::CLASS_NAME, self::FILE,
            array_merge( array( 'limit' => 100000 ), $params ) );
    }

    public function testTheCommandIsRegistered(): void
    {
        $this->assertSame( 'base.repairGeoEncodingCli',
            $this->commandClass( 'repair-geo-encoding' ),
            'the command is not wired up, so cli.php cannot resolve it' );
    }

    public function testItRepairsADoubleEncodedCity(): void
    {
        // 'mÃ¼nchen' is what the reader stored for 'münchen'. The token keeps the
        // fixture unique without changing that: it is ASCII.
        $id = $this->makeLocation( 'germany', 'bayern', 'mÃ¼nchen-' . $this->tok );

        $this->runRepair();

        $this->assertSame( 'münchen-' . $this->tok, $this->reload( $id )['city'] );
    }

    public function testItRepairsStateAndCountryOnTheSameRow(): void
    {
        // A row with two wrong columns is written once, not twice.
        $id = $this->makeLocation(
            'Ã¶sterreich-' . $this->tok,
            'baden-wÃ¼rttemberg-' . $this->tok,
            'kÃ¶ln-' . $this->tok
        );

        $this->runRepair();
        $row = $this->reload( $id );

        $this->assertSame( 'österreich-' . $this->tok, $row['country'] );
        $this->assertSame( 'baden-württemberg-' . $this->tok, $row['state'] );
        $this->assertSame( 'köln-' . $this->tok, $row['city'] );
    }

    /**
     * The id must survive untouched.
     *
     * location_dim.id is generateId(country . city) over the spelling the row was
     * created with, and fact rows join on it. Recomputing it from the repaired
     * text would strand every fact row pointing at the old one, which is a far
     * worse outcome than the mojibake this fixes.
     */
    public function testItLeavesTheRowIdAlone(): void
    {
        $id = $this->makeLocation( 'germany', 'bayern', 'nÃ¼rnberg-' . $this->tok );

        $this->runRepair();

        $this->assertSame( $id, $this->reload( $id )['id'],
            'the primary key changed, which orphans every fact row joined to it' );
    }

    /*
     * ---------------------------------------------------------------------
     * Rows that must come back exactly as they went in.
     * ---------------------------------------------------------------------
     */

    public function testItLeavesGenuineLatin1RangeNamesAlone(): void
    {
        // Written correctly, by a tracker that already had the fix. Rewriting
        // this is how a repair becomes a second round of damage.
        $city = 'são paulo-' . $this->tok;
        $id   = $this->makeLocation( 'brazil', 'são paulo-' . $this->tok, $city );

        $this->runRepair();
        $row = $this->reload( $id );

        $this->assertSame( $city, $row['city'] );
        $this->assertSame( 'são paulo-' . $this->tok, $row['state'] );
    }

    public function testItLeavesNonLatin1NamesAlone(): void
    {
        $city = 'москва-' . $this->tok;
        $id   = $this->makeLocation( 'russia', '東京-' . $this->tok, $city );

        $this->runRepair();
        $row = $this->reload( $id );

        $this->assertSame( $city, $row['city'] );
        $this->assertSame( '東京-' . $this->tok, $row['state'] );
    }

    public function testItLeavesAsciiNamesAlone(): void
    {
        $id = $this->makeLocation( 'united kingdom', 'england', 'london-' . $this->tok );

        $this->runRepair();
        $row = $this->reload( $id );

        $this->assertSame( 'london-' . $this->tok, $row['city'] );
        $this->assertSame( 'united kingdom', $row['country'] );
    }

    /*
     * ---------------------------------------------------------------------
     * The dry run, which is what an operator is told to use first.
     * ---------------------------------------------------------------------
     */

    public function testDryRunWritesNothing(): void
    {
        $city = 'dÃ¼sseldorf-' . $this->tok;
        $id   = $this->makeLocation( 'germany', 'nrw', $city );

        $this->runRepair( array( 'dry-run' => 1 ) );

        $this->assertSame( $city, $this->reload( $id )['city'],
            'dry-run rewrote a row, so an operator cannot rehearse the repair' );
    }

    public function testDryRunLeavesTheRowRepairableAfterwards(): void
    {
        $id = $this->makeLocation( 'germany', 'nrw', 'dÃ¼ren-' . $this->tok );

        $this->runRepair( array( 'dry-run' => 1 ) );
        $this->runRepair();

        $this->assertSame( 'düren-' . $this->tok, $this->reload( $id )['city'] );
    }

    /**
     * The command is bounded and meant to be re-run until it reports nothing, so
     * a second pass over a repaired row must be a no-op rather than a second
     * conversion.
     */
    public function testItIsIdempotent(): void
    {
        $id = $this->makeLocation( 'germany', 'bayern', 'wÃ¼rzburg-' . $this->tok );

        $this->runRepair();
        $once = $this->reload( $id )['city'];

        $this->runRepair();
        $twice = $this->reload( $id )['city'];

        $this->assertSame( 'würzburg-' . $this->tok, $once );
        $this->assertSame( $once, $twice, 'a second run changed an already repaired row' );
    }

    /**
     * A limit of zero or less falls back to the default rather than scanning
     * nothing, so a mistyped argument does not silently do no work and report
     * success.
     */
    public function testAnUnusableLimitFallsBackToTheDefault(): void
    {
        $id = $this->makeLocation( 'germany', 'hessen', 'kÃ¶then-' . $this->tok );

        $this->runCommand( self::CLASS_NAME, self::FILE, array( 'limit' => 0 ) );

        $this->assertSame( 'köthen-' . $this->tok, $this->reload( $id )['city'] );
    }
}
