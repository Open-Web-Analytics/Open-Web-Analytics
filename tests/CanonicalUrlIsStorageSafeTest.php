<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A canonical URL is safe to store, and safe wherever it is later rendered.
 *
 * makeUrlCanonical() decodes HTML entities so parse_url() sees the real URL,
 * and its return value goes to the event untouched -- setTrackerProperties()
 * re-applies the declared type only when a callback answers null. So whatever
 * survives that decode is what reaches the column.
 *
 * The treatment is percent-encoding rather than escaping. A URL is not HTML,
 * and escaping it for one output context breaks it for the others -- it has to
 * survive as an href, in a CSV and in a JSON response. What a URL has is a
 * grammar, and these characters cannot legally appear raw in one: a browser
 * encodes them before the request is made. So encoding them is the URL written
 * correctly, and it stays inert everywhere rather than in one place.
 */
final class CanonicalUrlIsStorageSafeTest extends TestCase
{
    private function stored( string $wire ): string
    {
        $definitions = Helpers::clientProperties();

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setSiteId( 'no-such-site-for-this-test' );
        $event->set( 'page_url', $wire );

        ( new Helpers() )->setTrackerProperties(
            $event, array( 'page_url' => $definitions['page_url'] ) );

        return (string) $event->get( 'page_url' );
    }

    /** @dataProvider mustNotSurviveRaw */
    public function testNothingThatCanOpenATagIsStored( string $wire ): void
    {
        $stored = $this->stored( $wire );

        foreach ( array( '<', '>', '"', "'" ) as $raw ) {
            $this->assertStringNotContainsString( $raw, $stored,
                "a raw $raw reached the column" );
        }
    }

    public static function mustNotSurviveRaw(): array
    {
        return array(
            // Arrives entity-encoded, so tag-stripping sees no tag to strip,
            // and the canonicaliser's decode is what would have made it one.
            'encoded on the wire' => array( 'https://x.test/&lt;img src=x onerror=alert(1)&gt;' ),
            'raw angle brackets'  => array( 'https://x.test/a < b > c' ),
            'attribute breakout'  => array( 'https://x.test/" onmouseover="alert(1)' ),
            'single quoted'       => array( "https://x.test/' onmouseover='alert(1)" ),
        );
    }

    /** @dataProvider rejectedSchemes */
    public function testAUrlWithAnUnusableSchemeIsNotRecorded( string $wire ): void
    {
        $this->assertSame( '', $this->stored( $wire ),
            'a URL with this scheme should be recorded as absent' );
    }

    public static function rejectedSchemes(): array
    {
        return array(
            'javascript'      => array( 'javascript:alert(1)' ),
            'mixed case'      => array( 'JaVaScRiPt:alert(1)' ),
            'data'            => array( 'data:text/html,x' ),
            'vbscript'        => array( 'vbscript:x' ),
            // A browser strips tab, newline and CR from inside a scheme, so this
            // IS javascript: to the thing that would run it. Reading the scheme
            // off the string rather than via parse_url() is what catches it:
            // upstream sanitising rewrites the tab, and parse_url() then reports
            // no scheme at all, skipping a check that only fires when one parses.
            'tab in scheme'   => array( "java\tscript:alert(1)" ),
        );
    }

    /** @dataProvider keptIntact */
    public function testAnOrdinaryUrlIsUnchanged( string $wire, string $expected ): void
    {
        $this->assertSame( $expected, $this->stored( $wire ) );
    }

    public static function keptIntact(): array
    {
        return array(
            'plain'            => array( 'https://x.test/ok', 'https://x.test/ok' ),
            'query string'     => array( 'https://x.test/ok?a=1&b=2', 'https://x.test/ok?a=1&b=2' ),
            'uppercase scheme' => array( 'HTTPS://x.test/Case', 'HTTPS://x.test/Case' ),
            'relative path'    => array( '/relative/path', '/relative/path' ),
            // A colon AFTER a slash is part of the path, not a scheme.
            'colon in path'    => array( 'https://x.test/p:with:colons', 'https://x.test/p:with:colons' ),
        );
    }

    /**
     * An existing percent-escape is left alone.
     *
     * '%' is not in the replacement set, so %3C stays %3C rather than becoming
     * %253C -- which would change the URL, and would change the document id
     * derived from it.
     */
    public function testExistingEscapesAreNotDoubleEncoded(): void
    {
        $this->assertSame( 'https://x.test/already%3Cencoded',
            $this->stored( 'https://x.test/already%3Cencoded' ) );
    }

    /** The encoding is the URL written correctly, so it still decodes back. */
    public function testTheEncodedFormStillDecodesToWhatWasObserved(): void
    {
        $stored = $this->stored( 'https://x.test/a < b' );

        $this->assertSame( 'https://x.test/a < b', urldecode( $stored ) );
    }

    /** Absence stays absence rather than becoming an empty-ish URL. */
    public function testNullPassesThrough(): void
    {
        $this->assertNull( Helpers::makeUrlStorageSafe( null ) );
        $this->assertSame( '', Helpers::makeUrlStorageSafe( '' ) );
    }
}
