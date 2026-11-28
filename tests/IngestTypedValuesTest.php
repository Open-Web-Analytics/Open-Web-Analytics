<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Event;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;
use OWA\Module\Base\Handler\EventRawHandlers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A value its declared type refuses is absent: text sent for an integer, an
 * array sent for a one-value property. And an integer its column cannot hold
 * is stored as NULL, rather than failing the beacon's INSERT.
 *
 * No database: setDataType(), setTrackerProperties() with properties that
 * have no callbacks, and the row builder.
 */
final class IngestTypedValuesTest extends TestCase
{
    public function testAnIntegerThatIsNotANumberIsAbsent(): void
    {
        foreach (['abc', '49,17', '1.2.3', '0x1A', 'NaN', [], ['1'], true] as $sent) {
            $this->assertNull(Helpers::setDataType($sent, 'integer'), var_export($sent, true));
        }

        foreach ([null, false, ''] as $absent) {
            $this->assertNull(Helpers::setDataType($absent, 'integer'), var_export($absent, true));
        }

        $this->assertSame(0, Helpers::setDataType('0', 'integer'));
        $this->assertSame(12.5, Helpers::setDataType('12.50', 'integer'));
        $this->assertSame(-3, Helpers::setDataType('-3', 'integer'));
    }

    public function testAnArrayForAOneValuePropertyIsAbsent(): void
    {
        foreach (['string', 'url', ''] as $type) {
            $this->assertNull(Helpers::setDataType(['x'], $type), $type);
            $this->assertSame('x', Helpers::setDataType('x', $type), $type);
        }
    }

    /** The refused value leaves the event, so the row cannot read what the beacon sent. */
    public function testARefusedValueLeavesTheEvent(): void
    {
        $all   = Helpers::allProperties();
        $event = new Event();
        $event->setProperties([
            'scroll_depth'        => 'deep',
            'form_length'         => '4',
            'dom_element_tag'     => ['a', 'b'],
            'content_group'       => 'Guides',
            'first_field_position' => '',
        ]);

        Helpers::getInstance()->setTrackerProperties($event, array_intersect_key($all, array_flip(
            ['scroll_depth', 'form_length', 'dom_element_tag', 'content_group', 'first_field_position'])));

        $this->assertFalse($event->get('scroll_depth'));
        $this->assertFalse($event->get('dom_element_tag'));
        $this->assertSame(4, $event->get('form_length'));
        $this->assertSame('Guides', $event->get('content_group'));
        $this->assertSame('', $event->get('first_field_position'), 'an absent value is left as it was');
    }

    /** @return array<string, mixed> */
    private function row(array $properties): array
    {
        $event = new Event();
        $event->setEventType('scroll');
        $event->setProperties($properties + [
            'site_id'    => md5('typed-values-test'),
            'visitor_id' => '4611686018427387905',
            'session_id' => '4611686018427387906',
            'ts'         => 1764000000000000,
            'yyyymmdd'   => 20251124,
        ]);

        $row = EventRawHandlers::rowFor($event);
        $this->assertIsArray($row);

        return $row;
    }

    public function testAnIntegerItsColumnCannotHoldIsStoredAsNull(): void
    {
        $row = $this->row([
            'scroll_depth'    => 200,
            'engagement_msec' => 99999999999,
            'click_x'         => 2147483648,
            'click_y'         => 2147483647,
            'page_width'      => -2147483649,
            'event_seq'       => 7,
        ]);

        $this->assertNull($row['scroll_depth'], 'TINYINT');
        $this->assertNull($row['engagement_msec'], 'INT');
        $this->assertNull($row['click_x'], 'INT, one past its maximum');
        $this->assertSame(2147483647, $row['click_y'], 'INT, at its maximum');
        $this->assertNull($row['page_width'], 'INT, one past its minimum');
        $this->assertSame(7, $row['event_seq']);
    }

    /** Past PHP's integer range a cast gives a wrong number; the column gets NULL. */
    public function testANumberBeyondPhpsIntegerRangeIsNull(): void
    {
        $row = $this->row(['revenue' => '99999999999999999999', 'ct_tax' => 1e30, 'ct_shipping' => '125']);

        $this->assertNull($row['revenue']);
        $this->assertNull($row['tax']);
        $this->assertSame(125, $row['shipping']);
    }

    /** An older addTransaction() sent an omitted order id as the text undefined. */
    public function testAnOrderIdOfUndefinedOrNullIsAbsent(): void
    {
        foreach (['undefined', 'null', ' Undefined '] as $sent) {
            $event = new Event();
            $event->set('ct_order_id', $sent);

            $this->assertNull(Helpers::orderId($sent, $event), $sent);
            $this->assertFalse($event->get('ct_order_id'), $sent);
        }

        $event = new Event();
        $this->assertSame('undefined-42', Helpers::orderId('undefined-42', $event));
    }

    /** setCurrency() puts the code on every event; only a purchase or refund stores it. */
    public function testOnlyAPurchaseOrRefundStoresACurrency(): void
    {
        foreach (['page_view', 'click', 'add_to_cart_custom'] as $type) {
            $event = new Event();
            $event->setEventType($type);
            $event->set('currency', 'EUR');

            $this->assertNull(Helpers::resolveCurrency('EUR', $event), $type);
            $this->assertFalse($event->get('currency'), $type);
        }

        $event = new Event();
        $event->setEventType('purchase');
        $event->set('currency', 'eur');
        $this->assertSame('EUR', Helpers::resolveCurrency('eur', $event));
    }
}
