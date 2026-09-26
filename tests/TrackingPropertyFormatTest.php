<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The property registry's four axes, each checked.
 *
 * The file used to group properties under request / client / server, and that
 * grouping carried three facts at once: how a value gets set, what it may be
 * read from, and -- because a callback reads whatever is already on the event --
 * the order derivations must run in. Anything implicit in a position is
 * something no test can hold, and four separate faults were living in that gap:
 *
 *   - the column a property lands in was stated in three other places
 *     (GoalVocabulary::SOURCE, the row builder's literal, the per-event param
 *     list) and two of them disagreed, which is how a purchase came to store its
 *     currency and no amount;
 *   - `events` absent meant "every event", so numeric_value -- declared with a
 *     default of 0 -- rode every page view carrying a param the event never had;
 *   - `default_value: false` was declared on four VARCHAR properties and three
 *     integers, where it either could not fire or put a boolean on the event for
 *     the row builder to launder into NULL;
 *   - and a beacon could assert tagged_source, which is attribution, because
 *     "client-settable" was a list beside the registry rather than a property of
 *     it.
 *
 * Every entry now says: set_by, from, events, and column when it differs. This
 * file is what stops the next one drifting.
 */
final class TrackingPropertyFormatTest extends TestCase
{
    /** @return array<string,array> */
    private function registry(): array
    {
        $path = OWA_DIR . 'modules/Base/config/tracking_properties.json';

        $config = json_decode( (string) file_get_contents( $path ), true );

        $this->assertIsArray( $config, "$path is not valid JSON: " . json_last_error_msg() );
        $this->assertGreaterThan( 70, count( $config ),
            'the registry came back nearly empty, so every assertion here would pass vacuously' );

        return $config;
    }

    /** @return string[] */
    private function rawColumns(): array
    {
        return array_keys( (array) \OWA\Core\CoreAPI::entityFactory(
            'base.event_raw' )->getProperties() );
    }

    /* ---------------- set_by ---------------- */

    public function testEveryEntryDeclaresHowItsValueGetsSet(): void
    {
        foreach ( $this->registry() as $name => $definition ) {

            $this->assertArrayHasKey( 'set_by', $definition, "$name does not declare set_by" );

            $this->assertContains( $definition['set_by'], Helpers::SET_BY,
                "$name is set_by '{$definition['set_by']}', which is not one of "
                . implode( ', ', Helpers::SET_BY ) );
        }
    }

    /** The old spellings of the axis are gone, not merely unused. */
    public function testNoEntryCarriesTheSupersededKeys(): void
    {
        foreach ( $this->registry() as $name => $definition ) {

            foreach ( array( 'wire', 'request-source', 'scope' ) as $gone ) {

                $this->assertArrayNotHasKey( $gone, $definition,
                    "$name carries '$gone'; the transport key, the request key and the "
                    . 'property it derives from are one `from` set now, disambiguated by set_by' );
            }
        }
    }

    /* ---------------- from ---------------- */

    public function testEveryEntryDeclaresWhatItComesFrom(): void
    {
        foreach ( $this->registry() as $name => $definition ) {

            $this->assertArrayHasKey( 'from', $definition, "$name does not declare from" );
            $this->assertIsArray( $definition['from'], "$name's from is not a set" );
            $this->assertNotEmpty( $definition['from'], "$name's from is empty" );

            foreach ( $definition['from'] as $source ) {

                $this->assertIsString( $source );
                $this->assertNotSame( '', trim( $source ), "$name has an empty source" );
            }
        }
    }

    /**
     * A derived property derives from PROPERTIES, and they have to exist.
     *
     * `from` means three different things by `set_by` -- wire keys, request keys,
     * property names -- and only this one is checkable against the registry
     * itself. A typo here is a derivation that silently reads nothing.
     */
    public function testADerivedPropertyNamesPropertiesThatExist(): void
    {
        $registry = $this->registry();

        foreach ( $registry as $name => $definition ) {

            if ( $definition['set_by'] !== 'event' ) {

                continue;
            }

            foreach ( $definition['from'] as $source ) {

                $this->assertArrayHasKey( $source, $registry,
                    "$name derives from '$source', which the registry does not declare" );
            }
        }
    }

    /** ...and nothing derives from itself, directly or in a two-step cycle. */
    public function testNoDerivationIsCircular(): void
    {
        $registry = $this->registry();

        foreach ( $registry as $name => $definition ) {

            if ( $definition['set_by'] !== 'event' ) {

                continue;
            }

            $this->assertNotContains( $name, $definition['from'],
                "$name derives from itself" );

            foreach ( $definition['from'] as $source ) {

                $upstream = $registry[ $source ] ?? array();

                if ( ( $upstream['set_by'] ?? '' ) !== 'event' ) {

                    continue;
                }

                $this->assertNotContains( $name, (array) $upstream['from'],
                    "$name and $source derive from each other" );
            }
        }
    }

    /* ---------------- the wire ---------------- */

    /**
     * ONLY A CLIENT PROPERTY CAN ARRIVE, and the gate reads the registry to know
     * it.
     *
     * This is the whole reason the axis is declared. A request that could set its
     * own city, its own region or its own campaign source is a request that can
     * forge what a report says, and that refusal used to be a list maintained
     * beside the registry -- which is a list that can be forgotten.
     */
    public function testOnlyAClientPropertyIsAdmittedFromTheWire(): void
    {
        $refused = array();

        foreach ( $this->registry() as $name => $definition ) {

            $admitted = Helpers::admitRequestParams( array( $name => 'x' ) );

            if ( $definition['set_by'] === 'client' ) {

                /* The wire key, not the property name: they differ for nps and
                   the ct_* family, and it is the key that has to be admitted. */
                foreach ( $definition['from'] as $key ) {

                    $this->assertSame( array( $key => 'x' ),
                        Helpers::admitRequestParams( array( $key => 'x' ) ),
                        "$name is client-set and its wire key '$key' was refused" );
                }

                continue;
            }

            if ( $admitted !== array() ) {

                $refused[] = $name;
            }
        }

        $this->assertSame( array(), $refused,
            "These are set by the request or derived from other properties, and a beacon "
            . "was able to assert them:\n  " . implode( "\n  ", $refused ) );
    }

    /** wireKeysFor() answers the client set and nothing else. */
    public function testTheWireKeysComeFromTheRegistry(): void
    {
        foreach ( $this->registry() as $name => $definition ) {

            $expected = $definition['set_by'] === 'client'
                ? $definition['from'] : array();

            $this->assertSame( $expected, Helpers::wireKeysFor( $name ),
                "wireKeysFor($name) disagrees with the registry" );
        }
    }

    /* ---------------- events ---------------- */

    public function testEveryEntryDeclaresWhichEventsCarryIt(): void
    {
        $known = Helpers::eventNames();

        foreach ( $this->registry() as $name => $definition ) {

            $this->assertArrayHasKey( 'events', $definition,
                "$name does not declare events. '*' says every event; absence says nothing, "
                . 'and absence is how numeric_value came to ride every page view.' );

            $this->assertNotEmpty( $definition['events'], "$name's events list is empty" );

            foreach ( $definition['events'] as $event ) {

                if ( $event === Helpers::EVERY_EVENT ) {

                    $this->assertCount( 1, $definition['events'],
                        "$name lists '*' beside named events, which cannot mean anything more" );

                    continue;
                }

                $this->assertContains( $event, $known,
                    "$name is declared for '$event', which is not an event name" );
            }
        }
    }

    /* ---------------- the destination ---------------- */

    /**
     * EVERY PROPERTY HAS EXACTLY ONE DESTINATION: a column, a params key, or
     * explicitly neither.
     *
     * `column` used to be declared only when it differed from the property name,
     * so an absent key meant "same name" -- and 32 of the entries have no column
     * at all, which made columnFor() answer `last_req`, `form_id` and
     * `session_referer` with total confidence. A guess that is right most of the
     * time is worse than no answer, because nothing downstream can tell which
     * kind it got.
     *
     * The third case is a real answer rather than an omission: the clock anchors,
     * the two marker flags and the landing URL are consumed during ingest and
     * stored by nothing.
     */
    public function testEveryPropertyHasExactlyOneDestination(): void
    {
        $columns = $this->rawColumns();

        $counts = array( 'column' => 0, 'param' => 0, 'nowhere' => 0 );

        foreach ( $this->registry() as $name => $definition ) {

            $has_column = array_key_exists( 'column', $definition );
            $has_param  = array_key_exists( 'param', $definition );

            $this->assertFalse( $has_column && $has_param,
                "$name declares both a column and a param; a value lands in one place" );

            if ( $has_column ) {

                $this->assertContains( $definition['column'], $columns,
                    "$name lands in '{$definition['column']}', which is not a column of "
                    . 'owa_event_raw' );

                $this->assertSame( $definition['column'], Helpers::columnFor( $name ) );
                $this->assertSame( '', Helpers::paramFor( $name ) );

                $counts['column']++;

                continue;
            }

            if ( $has_param ) {

                $this->assertNotSame( '', trim( (string) $definition['param'] ),
                    "$name declares an empty params key" );

                $this->assertSame( $definition['param'], Helpers::paramFor( $name ) );
                $this->assertSame( '', Helpers::columnFor( $name ) );

                $counts['param']++;

                continue;
            }

            /* Stored by nothing, and both accessors have to say so. */
            $this->assertSame( '', Helpers::columnFor( $name ),
                "$name reaches no column and columnFor() answered one anyway" );
            $this->assertSame( '', Helpers::paramFor( $name ) );

            $counts['nowhere']++;
        }

        foreach ( $counts as $kind => $n ) {

            $this->assertGreaterThan( 5, $n, "Only $n properties land in a $kind." );
        }
    }

    /**
     * A params key reaches the row, and the row builder no longer keeps its own
     * list of them.
     *
     * The hand-written map is where the purchase params went missing: it named
     * the column spellings while the wire sent the ct_ ones. Asserting the two
     * agree is the guard; asserting a couple of the renames by name is what makes
     * a failure readable.
     */
    public function testTheRowBuilderReadsTheRegistrysParams(): void
    {
        $handler = new \OWA\Module\Base\Handler\EventRawHandlers();

        $method = new ReflectionMethod( $handler, 'declaredParams' );
        $method->setAccessible( true );

        foreach ( array( 'purchase', 'click', 'file_download', 'custom_event' ) as $event_name ) {

            $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
            $event->setEventType( $event_name );

            $this->assertSame(
                Helpers::paramsForEvent( $event_name ),
                (array) $method->invoke( $handler, $event ),
                "the row builder and the registry disagree about $event_name's params" );
        }

        $purchase = Helpers::paramsForEvent( 'purchase' );

        $this->assertSame( 'items', $purchase['ct_line_items'] ?? null,
            'the line items are reached as params.items, not under the wire name' );

        $this->assertSame( 'gateway', $purchase['ct_gateway'] ?? null );
    }

    /* ---------------- default_value ---------------- */

    /**
     * A DEFAULT IS A VALUE OF THE PROPERTY'S OWN TYPE.
     *
     * Ten entries declared `false`: four VARCHARs, three integers where a
     * boolean is not a count, and three that are not `required` at all, so the
     * branch that would apply the default cannot fire. The string ones reached
     * the event as boolean false and were laundered into NULL by the row
     * builder's text() -- so anything reading the event in between saw false
     * where a city belongs.
     *
     * `(not set)` is exempt: it is the STORAGE label, never applied to the event,
     * which Entity::setProperties() substitutes for an empty value on a v1
     * column that declares it.
     */
    public function testADefaultValueMatchesItsDeclaredType(): void
    {
        foreach ( $this->registry() as $name => $definition ) {

            if ( ! array_key_exists( 'default_value', $definition ) ) {

                continue;
            }

            $value = $definition['default_value'];

            if ( $value === Helpers::ABSENT_VALUE_LABEL ) {

                continue;
            }

            $this->assertTrue( (bool) ( $definition['required'] ?? false ),
                "$name declares a default and is not required, so it can never be applied" );

            $type = $definition['data_type'] ?? 'string';

            switch ( $type ) {

                case 'boolean':
                    $this->assertIsBool( $value, "$name is boolean and its default is not" );
                    break;

                case 'integer':
                    $this->assertIsNumeric( $value, "$name is integer and its default is not" );
                    $this->assertIsNotBool( $value,
                        "$name is integer and its default is a boolean" );
                    break;

                default:
                    $this->assertIsString( $value,
                        "$name is $type and its default is a " . gettype( $value ) );
            }
        }
    }
}
