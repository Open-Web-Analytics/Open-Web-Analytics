<?php

use OWA\Module\Base\Classes\V2Event;
use OWA\Module\Base\Handler\EventRawHandlers;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * What the raw-row builder refuses at the edge: a visitor or session id its
 * BIGINT column cannot hold, and custom properties past the cap, logged once
 * per event however many there were.
 */
final class EventRawEdgeTest extends TestCase
{
    use CapturesLog;

    private function event(array $extra = []): \OWA\Module\Base\Classes\Event
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->setProperties($extra + [
            'site_id'       => 'owatest-edge-site',
            'visitor_id'    => '1790000000123456789',
            'session_id'    => 1790000000123,
            'ts'            => 1790000000000000,
            'yyyymmdd'      => 20260918,
            'page_location' => 'https://example.test/a',
        ]);

        return $event;
    }

    /** @return array<string, array{mixed, bool}> */
    public static function ids(): array
    {
        return [
            'digits'               => ['1790000000123456789', true],
            'an int'               => [17900001, true],
            'the largest'          => ['9223372036854775807', true],
            'one past the largest' => ['9223372036854775808', false],
            'twenty digits'        => ['17900000001234567890', false],
            'letters'              => ['17900abc', false],
            'a leading zero'       => ['0179', false],
            'zero'                 => [0, false],
            'negative'             => ['-5', false],
            'a decimal'            => ['1.5', false],
            'a trailing newline'   => ["1790\n", false],
            'an array'             => [['1790'], false],
        ];
    }

    /** @dataProvider ids */
    public function testAnIdIsAPositiveIntegerABigintHolds($id, bool $valid): void
    {
        $this->assertSame($valid, V2Event::isId($id));
    }

    /** @dataProvider ids */
    public function testAnEventWithAnIdThatIsNotOneIsDropped($id, bool $valid): void
    {
        foreach (['visitor_id', 'session_id'] as $key) {
            $row = null;
            $log = $this->logDuring(function () use (&$row, $key, $id) {
                $row = EventRawHandlers::rowFor($this->event([$key => $id]));
            });

            if ($valid) {
                $this->assertIsArray($row, $key);
            } else {
                $this->assertNull($row, $key);
                // Zero is no id at all, and is logged as one missing.
                $this->assertStringContainsString($id === 0 ? 'dropping an event, it carries no'
                    : sprintf('dropping an event, its %s is not a positive integer', str_replace('_', ' ', $key)), $log);
            }
        }
    }

    /** @return array<string, array{mixed, bool}> */
    public static function v1Ids(): array
    {
        return [
            'a 64-bit id'            => ['1790000000123456789', true],
            'a signed 32-bit crc32'  => ['-1482514472', true],
            'a negative int'         => [-2147418572, true],
            'the smallest'           => ['-9223372036854775808', true],
            'one past the smallest'  => ['-9223372036854775809', false],
            'minus zero'             => ['-0', false],
            'zero'                   => ['0', false],
            'letters'                => ['-17900abc', false],
            'an array'               => [['-1790'], false],
        ];
    }

    /**
     * 1.x's ids before January 2012 were crc32s, stored signed, so half are
     * negative. A migrated event keeps one; a beacon's is still refused.
     *
     * @dataProvider v1Ids
     */
    public function testAMigratedEventKeepsAnyIdASignedBigintHolds($id, bool $valid): void
    {
        foreach (['visitor_id', 'session_id'] as $key) {
            $row = null;
            $this->logDuring(function () use (&$row, $key, $id) {
                $row = EventRawHandlers::migratedRowFor($this->event([$key => $id]));
            });

            if ($valid) {
                $this->assertIsArray($row, $key);
                $this->assertSame((string) $id, (string) $row[$key], $key);
            } else {
                $this->assertNull($row, $key);
            }
        }
    }

    public function testABeaconWithANegativeIdIsStillDropped(): void
    {
        $row = null;
        $this->logDuring(function () use (&$row) {
            $row = EventRawHandlers::rowFor($this->event(['visitor_id' => '-1482514472']));
        });

        $this->assertNull($row);
    }

    public function testPropertiesPastTheCapAreLoggedOnce(): void
    {
        $extra = [];

        for ($i = 0; $i < EventRawHandlers::MAX_CUSTOM_PROPERTIES + 30; $i++) {
            $extra['eps_p' . $i] = 'v';
        }

        $extra['epn_count'] = 'not a number';

        $row = null;
        $log = $this->logDuring(function () use (&$row, $extra) {
            $row = EventRawHandlers::rowFor($this->event($extra));
        });

        $this->assertIsArray($row);
        $this->assertCount(EventRawHandlers::MAX_CUSTOM_PROPERTIES, (array) json_decode((string) $row['params'], true));
        $this->assertSame(1, substr_count($log, 'v2 ingest:'), $log);
        $this->assertStringContainsString('refused custom event properties: 31 over the cap ("p25", "p26", "p27", "p28", "p29", ...)', $log);
    }

    public function testANumberThatIsNotOneIsLoggedOnce(): void
    {
        $log = $this->logDuring(function () {
            EventRawHandlers::rowFor($this->event(['epn_a' => 'x', 'epn_b' => 'y', 'epn_c' => 3]));
        });

        $this->assertSame(1, substr_count($log, 'v2 ingest:'), $log);
        $this->assertStringContainsString('2 declared numeric and not ("a", "b")', $log);
    }

    /**
     * A custom event property named as one of OWA's params is refused, so it
     * neither replaces OWA's value nor passes as it, and does not count against
     * the cap.
     */
    public function testACustomPropertyNamedAsOneOfOwasParamsIsRefused(): void
    {
        $this->assertArrayHasKey('coupon', EventRawHandlers::owaParamKeys());
        $this->assertArrayHasKey('items', EventRawHandlers::owaParamKeys());
        $this->assertArrayNotHasKey('page_title', EventRawHandlers::owaParamKeys(), 'a column, not a param');

        $row = null;
        $log = $this->logDuring(function () use (&$row) {
            $row = EventRawHandlers::rowFor($this->event(['eps_coupon' => 'SITE10', 'epn_form_length' => 3, 'eps_plan' => 'pro']));
        });

        $this->assertSame(['plan' => 'pro'], (array) json_decode((string) $row['params'], true));
        $this->assertStringContainsString('2 named as a param of OWA\'s ("coupon", "form_length")', $log);
    }
}

