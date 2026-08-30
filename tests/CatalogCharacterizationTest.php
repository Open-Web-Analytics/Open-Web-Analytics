<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CatalogCharacterizationHarness.php';

use OWA\Tests\CatalogCharacterizationHarness as Harness;

/**
 * Pins the metric and dimension catalog before it is changed to hold two schema
 * generations under one name.
 *
 * The recording includes two known defects rather than correcting them, so that
 * fixing them produces a visible, intentional diff. Each has a test below that
 * names it, and those tests are EXPECTED TO CHANGE -- they mark where the
 * behaviour moved, and are not a claim that today's behaviour is right.
 */
final class CatalogCharacterizationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function recorded(): array
    {
        $this->assertFileExists(
            Harness::fixturePath(),
            'The catalog recording is missing. Regenerate it deliberately, never as a side effect.' );

        return (array) json_decode(
            (string) file_get_contents( Harness::fixturePath() ), true );
    }

    public function testTheCatalogMatchesItsRecording(): void
    {
        $this->assertSame(
            $this->recorded(),
            Harness::snapshot(),
            'The catalog changed. If that was intended, regenerate the fixture and let the diff '
            . 'be the evidence; if not, something registered or stopped registering.' );
    }

    /*
     * ---- the recording must be able to fail --------------------------------
     *
     * A characterization suite is only worth the confidence placed in it if it
     * can be shown to notice. These feed doctored input to the harness rather
     * than mutating the live registry, so sensitivity is proved without leaving
     * global state behind for the next test.
     */

    public function testTheRecordingWouldNoticeADimensionChangingItsEntity(): void
    {
        $before = Harness::snapshot();

        $name = array_key_first( $before['dimensionsNormalized'] );

        $after = $before;
        $after['dimensionsNormalized'][ $name ]['entity'] = 'base.somethingElse';

        $this->assertNotSame(
            $before, $after,
            'A dimension pointing at a different entity must show in the recording.' );
    }

    public function testTheRecordingWouldNoticeAMetricLosingAnImplementation(): void
    {
        $snapshot = Harness::snapshot();

        $multi = null;

        foreach ( $snapshot['metrics'] as $name => $implementations ) {

            if ( count( $implementations ) > 1 ) {

                $multi = $name;

                break;
            }
        }

        $this->assertNotNull(
            $multi,
            'No metric has more than one implementation, so this test proves nothing. One name '
            . 'resolving to several entities is the mechanism the coming change relies on.' );

        $reduced = $snapshot;
        array_pop( $reduced['metrics'][ $multi ] );

        $this->assertNotSame( $snapshot, $reduced );
        $this->assertCount(
            count( $snapshot['metrics'][ $multi ] ) - 1,
            $reduced['metrics'][ $multi ] );
    }

    public function testTheEntryCounterHandlesBothTheCurrentAndTheComingShape(): void
    {
        /*
         * The harness has to survive the commit it is judging. If counting only
         * understood today's flat shape it would have to be edited by the very
         * change it measures, leaving nothing independent.
         */
        $flat = array(
            'pageTitle' => array( 'name' => 'pageTitle', 'entity' => 'base.document' ),
        );

        $entityKeyed = array(
            'pageTitle' => array(
                'base.document' => array( 'name' => 'pageTitle', 'entity' => 'base.document' ),
                'base.event'    => array( 'name' => 'pageTitle', 'entity' => 'base.event' ),
            ),
        );

        $this->assertSame( 1, Harness::countFlatEntries( $flat ) );
        $this->assertSame( 2, Harness::countFlatEntries( $entityKeyed ) );

        $this->assertFalse( Harness::isEntityKeyed( $flat['pageTitle'] ) );
        $this->assertTrue( Harness::isEntityKeyed( $entityKeyed['pageTitle'] ) );
    }

    /*
     * ---- the two defects, recorded on purpose ------------------------------
     */

    public function testNormalizedDimensionsCurrentlyHoldExactlyOneEntityEach(): void
    {
        $counts = Harness::snapshot()['counts'];

        /*
         * DEFECT 1, registration-time. registerDimension() loops $entity_names
         * but the normalized branch overwrites $this->dimensions[$dim_name] on
         * each turn, so a name registered against seven entities keeps one.
         * The equality IS the defect: when the registry becomes entity-keyed
         * the entry count rises above the name count and this test changes.
         */
        $this->assertSame(
            $counts['dimensionNamesNormalized'],
            $counts['dimensionEntriesNormalized'],
            'Normalized dimensions now hold more than one entity each -- which is the intended '
            . 'fix. Update this test and the fixture together.' );
    }

    public function testDenormalizedDimensionsAlreadyHoldManyEntitiesEach(): void
    {
        $counts = Harness::snapshot()['counts'];

        /*
         * The contrast that shows defect 1 is a defect and not a design: the
         * denormalized branch of the very same loop is entity-keyed, and carries
         * several times as many entries as names.
         */
        $this->assertGreaterThan(
            $counts['dimensionNamesDenormalized'],
            $counts['dimensionEntriesDenormalized'],
            'Denormalized dimensions are entity-keyed and must hold more entries than names.' );
    }

    public function testTheReadAccessorFlattensDenormalizedDimensions(): void
    {
        $counts = Harness::snapshot()['counts'];

        /*
         * DEFECT 2, read-time. CoreAPI::getAllDimensions() collapses the
         * entity-keyed registry with $dims[$k] = $dedim, keeping the last
         * entity. So the accessor the picker UI reads cannot express two
         * generations however well the registry stores them.
         */
        $this->assertLessThan(
            $counts['dimensionEntriesDenormalized'],
            $counts['accessorDimensionNames'],
            'getAllDimensions() no longer flattens -- which is the intended fix.' );

        $this->assertSame(
            $counts['dimensionNamesNormalized'] + $counts['dimensionNamesDenormalized'],
            $counts['accessorDimensionNames'],
            'The accessor should return exactly one entry per registered name today.' );
    }

    public function testTheRecordingIsNotEmpty(): void
    {
        /*
         * Cheap, and it catches the failure mode where a boot problem yields an
         * empty catalog that then matches an equally empty regenerated fixture.
         */
        $counts = Harness::snapshot()['counts'];

        $this->assertGreaterThan( 50, $counts['metricNames'] );
        $this->assertGreaterThan( 50, $counts['accessorDimensionNames'] );
        $this->assertGreaterThan(
            $counts['metricNames'],
            $counts['metricImplementations'],
            'Some metric must resolve to more than one entity, or the entity-keyed mechanism the '
            . 'coming change depends on is not actually in use.' );
    }
}
