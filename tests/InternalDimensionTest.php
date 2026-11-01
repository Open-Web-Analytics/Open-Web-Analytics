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
    const INTERNAL = array( 'clickX', 'clickY', 'elementAnchor', 'anchorX', 'anchorY' );

    private static function picked(): array
    {
        return array_column( CustomReportEdit::dimensionChoices(), 'name' );
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

    public function testOnlyTheDeclaredOnesAreLeftOut(): void
    {
        $all = array_keys( (array) \OWA\Core\CoreAPI::getAllDimensions() );

        $this->assertSame( self::INTERNAL, array_values( array_diff( $all, self::picked() ) ) );
        $this->assertContains( 'viewportWidth', self::picked(), 'a window width is a fair thing to group by' );
    }
}
