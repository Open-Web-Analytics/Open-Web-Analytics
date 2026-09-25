<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A resolver that hands back padding, so the POSITION of the trim can be
 * asserted rather than assumed.
 */
final class PaddingResolver
{
    public static function pad( $value, $event )
    {
        return '  resolved with padding  ';
    }
}

/**
 * Leading and trailing whitespace is removed once, in the pipeline.
 *
 * It used to be removed in some paths and not others. The catch-all property
 * definition applies lowercaseString(), which is strtolower( trim() ); eight
 * resolvers trim for themselves; and Sanitize::cleanInput(), which every
 * explicitly-defined string property flows through, does not trim at all --
 * removeHiddenSpaces() only swaps a non-breaking space. So the properties that
 * escaped trimming were exactly the ones carrying their own definitions.
 *
 * That asymmetry had teeth rather than being untidy: ' Google ' and 'Google'
 * hashed to different dimension rows depending which code path derived the id.
 */
final class TrackingValuesAreTrimmedTest extends TestCase
{
    private function definitions(): array
    {
        return array_merge( Helpers::requestProperties(),
                            Helpers::clientProperties(),
                            Helpers::serverProperties() );
    }

    private function through( string $property, $value )
    {
        $definitions = $this->definitions();

        $this->assertArrayHasKey( $property, $definitions );

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( $property, $value );

        ( new Helpers() )->setTrackerProperties(
            $event, array( $property => $definitions[ $property ] ) );

        return $event->get( $property );
    }

    /** @dataProvider paddedValues */
    public function testTheEdgesAreTrimmed( string $property, $input, string $expected ): void
    {
        $this->assertSame( $expected, $this->through( $property, $input ) );
    }

    public static function paddedValues(): array
    {
        return array(
            'spaces'          => array( 'page_title', '  Spaced Title  ', 'Spaced Title' ),
            'trailing break'  => array( 'page_title', "Title\n",          'Title' ),
            'tabs'            => array( 'page_title', "\tTitle\t",        'Title' ),
            /*
             * cv1_value was here. The cv{n} slots are not part of the current
             * vocabulary -- the tracker emits no cv key -- so an example drawn
             * from them tests the compat layer, not this one.
             */
            'content group'   => array( 'content_group', '  Docs  ',      'Docs' ),
            'user name'       => array( 'user_name',  '  Peter Adams  ',  'Peter Adams' ),
            'a url'           => array( 'page_url',   '  https://x.test/a  ', 'https://x.test/a' ),
        );
    }

    /**
     * Case is NOT touched.
     *
     * Trimming is value hygiene; lowercasing is identity normalisation and
     * belongs to the hash, not the value. A page title, a city name and a source
     * domain are all displayed as recorded. Properties that lowercase today do
     * it in their own callbacks and keep doing it.
     */
    public function testCaseIsPreserved(): void
    {
        $this->assertSame( 'Hello World', $this->through( 'page_title', '  Hello World  ' ) );
        $this->assertSame( 'MiXeD CaSe Title', $this->through( 'page_title', ' MiXeD CaSe Title ' ) );

        /*
         * Asserted on ASCII deliberately. Sanitize::cleanInput() HTML-entity
         * encodes non-ASCII on the way in -- 'Grüße' becomes 'Gr&uuml;&szlig;e'
         * -- which is long-standing and is what keeps stored payloads inert.
         * Trimming neither causes nor changes it, and asserting a round-trip
         * here would be testing the encoder, not this change.
         */

        /*
         * Not asserted on a custom variable: cv{n}_name and cv{n}_value are
         * added dynamically and pick up the CATCH-ALL definition, whose callback
         * is lowercaseString(). So they are lowercased today, and this change
         * neither causes that nor fixes it -- whether user-supplied values
         * should be case-folded is its own question.
         */
    }

    /** Only the edges. Interior spacing is content. */
    public function testInteriorWhitespaceSurvives(): void
    {
        $this->assertSame( 'a  b', $this->through( 'page_title', 'a  b' ) );
        $this->assertSame( 'two  spaces here', $this->through( 'content_group', '  two  spaces here  ' ) );
    }

    /**
     * It runs after the callbacks, not only on the raw input.
     *
     * setDataType() sanitises the value as it ARRIVES, and a callback's return
     * goes to the event untouched -- which is how a derivation that reintroduced
     * padding would have slipped through. resolveSource() happens to trim for
     * itself; this asserts the pipeline no longer depends on each resolver
     * remembering to.
     */
    public function testAResolvedValueIsTrimmedToo(): void
    {
        /*
         * Deliberately a synthetic resolver rather than a real one. Asserting
         * on resolveSource() proved nothing: it trims for itself, so the test
         * passed with the trim moved to BEFORE the callbacks -- vacuous, and it
         * survived a mutation that reintroduced the defect. This callback
         * returns padding unconditionally, so only a trim placed after the
         * filter can clean it up.
         *
         * A unique property name per run: registerFilter() is global and
         * registerCallbacks() dedupes per process, so reusing a real property
         * name would leak this resolver into every later test in the process.
         */
        $property = 'trim_probe_' . bin2hex( random_bytes( 4 ) );

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        ( new Helpers() )->setTrackerProperties( $event, array(
            $property => array(
                'required'  => false,
                'data_type' => 'string',
                'callbacks' => array( array( PaddingResolver::class, 'pad' ) ),
            ),
        ) );

        $this->assertSame( 'resolved with padding', $event->get( $property ),
            'a value produced by a callback reached the event with padding' );
    }

    /**
     * A value that is nothing but whitespace is absence, and takes the storage
     * label at the column like any other absence.
     */
    public function testAnAllWhitespaceValueBecomesAbsence(): void
    {
        // through the pipeline it is trimmed to nothing...
        $this->assertSame( '', (string) $this->through( 'page_title', '   ' ) );

        /*
         * ...and the column treats it as absence either way. The entity is the
         * last line of defence here: a caller that hands setProperties() a value
         * directly has not been through the pipeline's trim, and a column that
         * stored '   ' would be neither a value nor the label every other empty
         * row carries.
         */
        foreach ( array( '   ', "\t", '', null, false ) as $nothing ) {

            $document = \OWA\Core\CoreAPI::entityFactory( 'base.document' );
            $document->setProperties( array( 'page_title' => $nothing ) );

            $this->assertSame( Helpers::ABSENT_VALUE_LABEL, $document->get( 'page_title' ),
                var_export( $nothing, true ) . ' should store as absence' );
        }
    }

    /** Non-text declared types are left alone. */
    public function testNonTextTypesAreUntouched(): void
    {
        $this->assertSame( array( 'string', 'url', '' ), Helpers::TRIMMED_TYPES );

        foreach ( array( 'json', 'boolean', 'integer' ) as $type ) {
            $this->assertNotContains( $type, Helpers::TRIMMED_TYPES );
        }
    }
}
