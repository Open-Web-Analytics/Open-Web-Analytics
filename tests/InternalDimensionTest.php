<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\CustomReportEdit;
use OWA\Module\Base\Controller\OverlayLauncher;

/**
 * An internal dimension is queryable and not offered.
 *
 * The heatmap reads its clicks through the reports API, which resolves only
 * registered names, so its coordinates and element anchor have to be
 * dimensions. Nobody groups a report by a hash or by thousandths of an element,
 * so the pickers leave them out.
 */
final class InternalDimensionTest extends TestCase
{
    const INTERNAL = [ 'clickX', 'clickY', 'elementAnchor', 'anchorX', 'anchorY' ];

    private static function picked(): array
    {
        return array_column( CustomReportEdit::dimensionChoices( null ), 'name' );
    }

    public function testTheHeatmapsDimensionsAreRegisteredAndNotOffered(): void
    {
        $all = (array) \OWA\Core\CoreAPI::getAllDimensions();

        foreach ( self::INTERNAL as $name ) {
            $this->assertArrayHasKey( $name, $all );
            $this->assertTrue( ! empty( $all[ $name ]['internal'] ), "$name is declared internal" );
            $this->assertNotContains( $name, self::picked(), "$name is offered in the picker" );
        }
    }

    public function testEveryDimensionTheHeatmapAsksForStillResolves(): void
    {
        foreach ( explode( ',', OverlayLauncher::HEATMAP_DIMENSIONS ) as $name ) {
            $this->assertTrue( \OWA\Module\Base\Classes\CustomReports::isDimension( $name ), $name );
        }
    }

    /** Nor in a grid's table chooser or the segment picker, which read relatedDimensions(). */
    public function testTheChoosersLeaveThemOut(): void
    {
        $offered = [];

        foreach ( ( new \OWA\Module\Base\Classes\ResultSetManager() )->relatedDimensions() as $group ) {
            $offered = array_merge( $offered, array_column( $group, 'name' ) );
        }

        $this->assertContains( 'viewportWidth', $offered );

        foreach ( self::INTERNAL as $name ) {
            $this->assertNotContains( $name, $offered, "$name is offered in a table chooser" );
        }

        $segment = ( new \OWA\Module\Base\Classes\ReportSegment( '', 'today', '', '' ) )->options();

        foreach ( (array) $segment['dimensions'] as $group ) {
            foreach ( self::INTERNAL as $name ) {
                $this->assertNotContains( $name, array_column( $group, 'name' ), "$name is offered in the segment picker" );
            }
        }
    }

    public function testOnlyTheDeclaredOnesAreLeftOut(): void
    {
        $all = array_keys( (array) \OWA\Core\CoreAPI::getAllDimensions() );

        $this->assertSame( self::INTERNAL, array_values( array_diff( $all, self::picked() ) ) );
        $this->assertContains( 'viewportWidth', self::picked(), 'a window width is a fair thing to group by' );
    }
}
