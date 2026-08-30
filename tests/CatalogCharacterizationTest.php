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

    public function testNormalizedDimensionsAreKeyedByEntity(): void
    {
        /*
         * The shape, not a count.
         *
         * An earlier version asserted entries outnumbered names, using userName
         * as its example -- which held only because userName was MISREGISTERED
         * as normalized against seven entities. Fixing that left the assertion
         * pinning a defect, and it began failing the moment the defect went. A
         * capability should be tested directly, not through the accident that
         * happened to exercise it.
         *
         * Every normalized dimension currently names exactly one entity, which
         * is what a normalized dimension SHOULD do: it points at the table it
         * joins. The structure still has to hold several, because that is what
         * lets a second schema generation register under an existing name.
         */
        $snapshot = Harness::snapshot();

        $this->assertNotEmpty( $snapshot['dimensionsNormalized'] );

        foreach ( $snapshot['dimensionsNormalized'] as $name => $entry ) {

            $this->assertTrue(
                Harness::isEntityKeyed( $entry ),
                "The normalized dimension '$name' is not keyed by entity, so a second "
                . 'definition under this name would overwrite it rather than sit beside it.' );
        }
    }

    public function testTheRegistryHoldsSeveralEntitiesUnderOneName(): void
    {
        /*
         * Exercised through the denormalized registry, which shares the shape
         * and genuinely carries multi-entity names -- `date` is registered
         * against every fact table. Both halves are now name => entity =>
         * registration, so this proves the structure both rely on.
         */
        $counts = Harness::snapshot()['counts'];

        $this->assertGreaterThan(
            $counts['dimensionNamesDenormalized'],
            $counts['dimensionEntriesDenormalized'] );

        $entities = \OWA\Core\CoreAPI::serviceSingleton()
            ->getDimensionEntities( 'userName' );

        $this->assertSame(
            array(), $entities,
            'userName is denormalized now, so it holds no NORMALIZED entities. If this starts '
            . 'returning entities it has been re-registered the old way.' );
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

    public function testAnUnscopedAnswerStillReturnsOneEntryPerName(): void
    {
        $counts = Harness::snapshot()['counts'];

        /*
         * getAllDimensions() flattens by letting the last entity win. That was
         * DEFECT 2 while it was the only thing on offer; it is now the
         * deliberate unscoped behaviour, kept because the picker and its
         * validation are written against one entry per name. The scoped call
         * below is what a second generation will use.
         */
        $this->assertSame(
            $counts['dimensionNamesNormalized'] + $counts['dimensionNamesDenormalized'],
            $counts['accessorDimensionNames'] );

        $entry = \OWA\Core\CoreAPI::getAllDimensions()['userName'];

        $this->assertArrayHasKey(
            'column', $entry,
            'An unscoped entry must still be a registration, not a map of them -- ten callers '
            . 'read it directly.' );
    }

    public function testScopingAnswersOnlyDimensionsDefinedOnThoseEntities(): void
    {
        $scoped = \OWA\Core\CoreAPI::getAllDimensions( array( 'base.session' ) );
        $all    = \OWA\Core\CoreAPI::getAllDimensions();

        $this->assertLessThan(
            count( $all ), count( $scoped ),
            'Scoping to one entity must narrow the catalog.' );

        $this->assertArrayHasKey( 'userName', $scoped );

        $this->assertSame(
            'base.session', $scoped['userName']['entity'],
            'A scoped answer must give the definition for the entity asked for, not whichever '
            . 'was registered last.' );
    }

    public function testANameDefinedOnNoScopedEntityIsAbsentEntirely(): void
    {
        /*
         * Absent rather than present-and-wrong. This is the property that makes
         * scoping structural: a caller cannot accidentally use a dimension from
         * the other generation, because it is not in the answer to filter out.
         */
        $scoped = \OWA\Core\CoreAPI::getAllDimensions( array( 'base.nonexistentEntity' ) );

        $this->assertSame( array(), $scoped );
    }

    public function testGetDimensionAnswersByEntityWhenAsked(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        /*
         * siteDomain rather than userName: a dimension that is genuinely
         * normalized, naming the table it joins.
         */
        $this->assertSame(
            'base.site',
            $service->getDimension( 'siteDomain', 'base.site' )['entity'] );

        $this->assertNull(
            $service->getDimension( 'siteDomain', 'base.nonexistentEntity' ),
            'An entity a dimension is not defined on must answer nothing, not a fallback.' );

        $this->assertNotNull(
            $service->getDimension( 'siteDomain' ),
            'Asking without an entity must still answer, as the flat registry did.' );
    }

    public function testEveryEntityStillReachesTheDimensionsItDid(): void
    {
        /*
         * The regression this file failed to catch the first time.
         *
         * getAllRelatedDimensions() reads $service->dimensions as a PUBLIC
         * PROPERTY, so re-keying the registry changed what it saw without any
         * accessor being involved -- and both accessors were verified
         * byte-identical against master, which is exactly why the change looked
         * safe. Its answer silently halved, 305 dimensions to 153, and the
         * reports that broke did so by dropping constraints and returning MORE
         * rows than asked for.
         *
         * Counted per entity rather than in total so a compensating pair of
         * changes cannot cancel out.
         */
        $recorded = $this->recorded()['relatedDimensions'];
        $actual   = Harness::relatedDimensions();

        $this->assertSame( array_keys( $recorded ), array_keys( $actual ) );

        foreach ( $recorded as $entity => $families ) {

            $this->assertSame(
                $families, $actual[ $entity ],
                "The dimensions reachable from $entity changed. A report can only constrain by "
                . 'a dimension it can reach, and an unreachable constraint is dropped silently.' );
        }
    }

    public function testTheRelatedRecordingIsSubstantial(): void
    {
        /*
         * Guards the guard. If getAllRelatedDimensions() ever returns nothing --
         * a construction failure, say -- every per-entity comparison above would
         * still pass against an equally empty recording.
         */
        $total = 0;

        foreach ( Harness::relatedDimensions() as $families ) {

            foreach ( $families as $names ) {

                $total += count( $names );
            }
        }

        $this->assertGreaterThan( 250, $total );
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
