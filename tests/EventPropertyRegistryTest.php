<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;
use OWA\Module\Base\Classes\V2Event;

/**
 * Which properties an event of a given name may carry.
 *
 * WHY THIS EXISTS. Three namespaces nearly align and none of them stated the
 * event a property belongs to: the wire (~130 registered properties), the raw
 * row (59 columns), and the dimension vocabulary. Nothing could refuse a goal
 * condition written against `medium` on a page_view -- a reading the cube pass
 * derives, that no row carries and no ingest-time match can satisfy. Nine such
 * conditions exist on the installation this was written on, every one of them
 * unsatisfiable, and every one saved without complaint.
 *
 * So the registry is a guard, not a convenience, and these cases hold the two
 * halves of that guard: that the pass-derived readings are offered for NO
 * event, and that the event-specific properties are offered for THEIRS and not
 * for everyone's.
 */
final class EventPropertyRegistryTest extends TestCase
{
    /** Every v2 event name that reaches owa_event_raw. */
    private static function storableEvents(): array
    {
        return array_merge(
            array_values(V2Event::typeMap()),
            (array) owa_coreAPI::getSetting('base', 'v2_event_types'),
            [V2Event::MARKER_SESSION_START, V2Event::MARKER_FIRST_VISIT]
        );
    }

    /**
     * THE CASE THE NINE BROKEN GOALS WOULD HAVE FAILED.
     *
     * source, medium, campaign, ad and search_terms are derived by SourceStep
     * and friends from the evidence columns. They are readings, not properties
     * -- no beacon carries them and no row stores them -- so no event may
     * offer them.
     */
    public function testThePassDerivedReadingsAreOfferedForNoEvent(): void
    {
        $offered = [];

        foreach (self::storableEvents() as $event) {

            foreach (['source', 'medium', 'campaign', 'ad', 'search_terms'] as $reading) {

                if (in_array($reading, Helpers::propertiesForEvent($event), true)) {
                    $offered[] = $event . '.' . $reading;
                }
            }
        }

        $this->assertSame([], $offered,
            'These are cube-pass readings. Offering one as a condition property produces a '
          . 'goal that saves cleanly and can never fire, which is how this installation '
          . 'acquired nine of them.');
    }

    /**
     * An empty `events` list means NO event, not every event.
     *
     * Asserted against the declared config rather than a synthetic one, so it
     * fails if a reading ever loses its empty list -- which is the realistic
     * regression. It does NOT distinguish isset() from array_key_exists() in
     * the reader: isset([]) is true, so both spellings agree here. The case
     * they disagree on is a declared null, which nothing declares.
     */
    public function testAnEmptyEventListIsNotReadAsUniversal(): void
    {
        $declared = json_decode(
            (string) file_get_contents(OWA_DIR . 'modules/Base/config/tracking_properties.json'), true);

        $empty = [];

        foreach (['request', 'client', 'server'] as $scope) {
            foreach ((array) $declared[$scope] as $name => $definition) {
                if (array_key_exists('events', $definition) && $definition['events'] === []) {
                    $empty[] = $name;
                }
            }
        }

        $this->assertNotEmpty($empty, 'nothing declares an empty list, so this proves nothing');

        foreach (self::storableEvents() as $event) {
            foreach ($empty as $name) {
                $this->assertNotContains($name, Helpers::propertiesForEvent($event),
                    $name . ' declares no events but is offered on ' . $event);
            }
        }
    }

    /** An event-specific property belongs to its event and not to others. */
    public static function eventSpecificProvider(): array
    {
        return [
            'click coordinates'  => ['click_x', 'click', ['page_view', 'purchase']],
            'clicked element'    => ['element_path', 'click', ['page_view', 'scroll']],
            'download name'      => ['file_name', 'file_download', ['click', 'page_view']],
            'download extension' => ['file_extension', 'file_download', ['click', 'page_view']],
            'scroll depth'       => ['scroll_depth', 'scroll', ['page_view', 'click']],
            'site search term'   => ['search_term', 'view_search_results', ['page_view', 'click']],
            'form id'            => ['form_id', 'form_start', ['page_view', 'click']],
            'order total'        => ['ct_total', 'purchase', ['page_view', 'click']],
        ];
    }

    /** @dataProvider eventSpecificProvider */
    public function testAnEventSpecificPropertyIsScopedToItsEvent(
        string $property, string $belongsTo, array $notOn): void
    {
        $this->assertContains($property, Helpers::propertiesForEvent($belongsTo),
            $property . ' must be offered on ' . $belongsTo);

        foreach ($notOn as $event) {
            $this->assertNotContains($property, Helpers::propertiesForEvent($event),
                $property . ' must not be offered on ' . $event);
        }
    }

    /**
     * The registry agrees with the code that actually stores the params.
     *
     * declaredParams() is the other statement of the same fact -- which params
     * ingest writes for which event type -- and the two drifting apart is how a
     * property comes to be offered for an event that never carries it.
     */
    public function testTheRegistryAgreesWithWhatIngestStores(): void
    {
        $handlers = new ReflectionClass(\OWA\Module\Base\Handler\EventRawHandlers::class);
        $method   = $handlers->getMethod('declaredParams');
        $method->setAccessible(true);

        $instance = $handlers->newInstanceWithoutConstructor();

        $disagreements = [];

        foreach (['click', 'file_download', 'view_search_results',
                  'form_start', 'form_submit', 'purchase', 'custom_event'] as $event) {

            $stub = new class($event) {
                private $type;
                public function __construct($type) { $this->type = $type; }
                public function getEventType() { return $this->type; }
            };

            foreach ((array) $method->invoke($instance, $stub) as $param) {

                // A param ingest stores for this event must be a property the
                // registry agrees the event carries -- unless it is not a
                // registered property at all, which is its own gap.
                if (!Helpers::propertiesForEvent($event) || !self::isRegistered($param)) {
                    continue;
                }

                if (!in_array($param, Helpers::propertiesForEvent($event), true)) {
                    $disagreements[] = sprintf(
                        'ingest stores %s on %s, the registry says it does not carry it',
                        $param, $event);
                }
            }
        }

        $this->assertSame([], $disagreements, implode("\n", $disagreements));
    }

    private static function isRegistered(string $name): bool
    {
        static $all = null;

        if ($all === null) {
            $declared = json_decode(
                (string) file_get_contents(OWA_DIR . 'modules/Base/config/tracking_properties.json'), true);

            $all = array_merge(
                array_keys($declared['request']),
                array_keys($declared['client']),
                array_keys($declared['server']));
        }

        return in_array($name, $all, true);
    }

    /** An event name is required: there is no vocabulary without one. */
    public function testAnEmptyEventNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Helpers::propertiesForEvent('');
    }
}
