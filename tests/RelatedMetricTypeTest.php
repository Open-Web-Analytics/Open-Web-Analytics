<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A result set's related metrics say what kind each is.
 *
 * The grid shows a row's share of the total beside a count or a sum, and not
 * beside a ratio: two rows' bounce rates do not add up to the site's. It
 * reads which is which from metric_type here (OWA.dataGrid.SHARE_TYPES).
 */
final class RelatedMetricTypeTest extends TestCase
{
    private function related(): array
    {
        $rsm    = new \OWA\Module\Base\Classes\ResultSetManager;
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );
        $byName = array();

        foreach ( $rsm->getAllRelatedMetrics( $entity ) as $group => $metrics ) {

            foreach ( $metrics as $m ) {

                $byName[ $m['name'] ] = $m;
            }
        }

        return $byName;
    }

    public function testEachRelatedMetricCarriesItsKind(): void
    {
        $related = $this->related();

        $this->assertSame( 'count', $related['pageViews']['metric_type'] );
        $this->assertSame( 'sum', $related['taxRevenue']['metric_type'] );
        $this->assertSame( 'ratio', $related['ecommerceConversionRate']['metric_type'] );
    }

    public function testNoneIsMissingIt(): void
    {
        foreach ( $this->related() as $name => $m ) {

            $this->assertArrayHasKey( 'metric_type', $m, $name );
            $this->assertNotSame( '', $m['metric_type'], $name );
        }
    }
}
