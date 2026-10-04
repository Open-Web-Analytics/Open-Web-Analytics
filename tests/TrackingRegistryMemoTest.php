<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The registry's views are built once per process and kept.
 *
 * The row builder asks for them on every event, and rebuilt per call they cost
 * 2.8 ms an event. Kept, a view must still be the one its arguments ask for:
 * a cache keyed wrongly answers one event type's question with another's.
 */
final class TrackingRegistryMemoTest extends TestCase
{
    public function testEachEventKeepsItsOwnView(): void
    {
        $views = [];

        // Interleaved, so a view cached for one name is in place when the
        // next is asked for.
        foreach (['page_view', 'purchase', 'click', 'page_view', 'purchase'] as $event) {
            $views[$event][] = [Helpers::propertiesForEvent($event), Helpers::paramsForEvent($event)];
        }

        $this->assertSame($views['page_view'][0], $views['page_view'][1]);
        $this->assertSame($views['purchase'][0], $views['purchase'][1]);

        $this->assertNotSame($views['page_view'][0][0], $views['purchase'][0][0]);
        $this->assertArrayHasKey('ct_line_items', $views['purchase'][0][1], 'a purchase carries its line items');
        $this->assertArrayNotHasKey('ct_line_items', $views['page_view'][0][1]);
        $this->assertArrayNotHasKey('ct_line_items', $views['click'][0][1]);
    }

    public function testEveryPropertyIsInTheMergedView(): void
    {
        $all = Helpers::allProperties();

        $this->assertSame($all, Helpers::allProperties());

        foreach (Helpers::propertiesForEvent('page_view') as $property) {
            $this->assertArrayHasKey($property, $all);
        }
    }
}
