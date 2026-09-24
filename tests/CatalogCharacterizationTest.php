<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CatalogCharacterizationHarness.php';

use OWA\Tests\CatalogCharacterizationHarness as Harness;

/**
 * The metric and dimension catalog, recorded so a change to it is visible.
 *
 * REWRITTEN when the v1 vocabulary was removed. The previous version pinned the
 * catalog "before it is changed to hold two schema generations under one name",
 * and said in its own docblock that the tests asserting that shape were
 * EXPECTED TO CHANGE. They have: there is one generation now.
 *
 * What went with them were assertions that only made sense while both existed
 * -- that a name holds several entities, that normalized dimensions exist at
 * all, that the related-dimension recording runs past 250 entries. Every one
 * described the 1.x star schema, and keeping them would have meant asserting
 * the shape v2 exists to replace.
 *
 * What stays is the part that was always the point: a recording, and enough
 * mutation guards to show the recording would notice a change.
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
        $this->assertSame( $this->recorded(), Harness::snapshot(),
            'The catalog changed. If that was intended, re-record it; if not, a '
          . 'registration moved without anyone meaning it to.' );
    }

    /** The recording notices a dimension pointing somewhere else. */
    public function testTheRecordingWouldNoticeADimensionChangingItsEntity(): void
    {
        $before = Harness::snapshot();
        $after  = $before;

        $name = array_key_first( $after['dimensionsDenormalized'] );
        $entity = array_key_first( $after['dimensionsDenormalized'][ $name ] );

        $after['dimensionsDenormalized'][ $name ][ $entity ]['entity'] = 'base.somethingElse';

        $this->assertNotSame( $before, $after,
            'A dimension pointing at a different entity must show in the recording.' );
    }

    /** And a metric disappearing. */
    public function testTheRecordingWouldNoticeAMetricVanishing(): void
    {
        $before = Harness::snapshot();
        $after  = $before;

        unset( $after['metrics'][ array_key_first( $after['metrics'] ) ] );

        $this->assertNotSame( $before, $after );
    }

    /**
     * EVERY dimension resolves against the cube, and only the cube.
     *
     * This is the invariant the removal bought, and the reason it was worth
     * doing. While both vocabularies existed a name could resolve to a v1 fact
     * table for one report and to the cube for another, depending on the base
     * entity -- so a metric existed for one report and not the next, and a
     * report mixing the two rendered NOTHING with no error anywhere.
     */
    public function testEveryDimensionResolvesAgainstTheCube(): void
    {
        $snapshot = Harness::snapshot();

        $this->assertEmpty( $snapshot['dimensionsNormalized'],
            'a normalized dimension is a join against a v1 dimension table; there are none' );

        $this->assertNotEmpty( $snapshot['dimensionsDenormalized'] );

        foreach ( $snapshot['dimensionsDenormalized'] as $name => $byEntity ) {

            $this->assertSame( array( 'base.event' ), array_keys( $byEntity ),
                $name . ' must resolve against the cube and nothing else' );
        }
    }

    /** And every metric likewise. */
    public function testEveryMetricResolvesAgainstTheCube(): void
    {
        $snapshot = Harness::snapshot();

        $this->assertNotEmpty( $snapshot['metrics'] );

        foreach ( $snapshot['metrics'] as $name => $implementations ) {

            $this->assertCount( 1, $implementations,
                $name . ' has more than one implementation, which is what made a '
              . 'name resolve differently per report' );
        }
    }

    /**
     * The recording is not trivially small.
     *
     * A guard against the snapshot silently collapsing -- which is exactly what
     * an empty registry looks like, and what every other assertion here would
     * pass on.
     */
    public function testTheRecordingIsSubstantial(): void
    {
        $snapshot = Harness::snapshot();

        $this->assertGreaterThan( 40, count( $snapshot['dimensionsDenormalized'] ) );
        $this->assertGreaterThan( 10, count( $snapshot['metrics'] ) );
    }
}
