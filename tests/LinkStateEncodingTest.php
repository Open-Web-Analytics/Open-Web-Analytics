<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * makeLink() carries the request's link state and the caller's parameters
 * URL-encoded: a value holding & = + # or % stays one parameter, rather than
 * adding others to the link or cutting it short.
 */
final class LinkStateEncodingTest extends TestCase
{
    public function testAStateValueStaysOneParameter(): void
    {
        $t = new \OWA\Core\Template();
        $t->caller_params['link_state'] = [ 'siteId' => '1&owa_do=base.users#x', 'period' => 'today' ];

        $link = html_entity_decode( $t->makeLink( [ 'do' => 'base.report' ], true ) );
        parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

        $this->assertSame( '1&owa_do=base.users#x', $query['siteId'] ?? null );
        $this->assertSame( 'base.report', $query['do'] ?? null );
        $this->assertArrayNotHasKey( 'owa_do', $query );
        $this->assertSame( 'today', $query['period'] ?? null );
        $this->assertNull( parse_url( $link, PHP_URL_FRAGMENT ), 'the link was cut short' );
    }

    /** A caller's value is encoded too, and reads back as given. */
    public function testAGivenParamIsCarriedAsGiven(): void
    {
        $t = new \OWA\Core\Template();

        $link = html_entity_decode( $t->makeLink( [ 'do' => 'reports', 'constraints' => 'host==a b&c' ] ) );
        parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

        $this->assertSame( 'host==a b&c', $query['constraints'] ?? null );
    }

    /** The overlay launcher's page path: + & # and % survive the link. */
    public function testAPagePathWithReservedCharactersRoundTrips(): void
    {
        $t = new \OWA\Core\Template();

        foreach ( [ '/a+b&c#d', '/50%off', '/x?y=1&z=2', '/caf%C3%A9' ] as $path ) {

            $link = html_entity_decode( $t->makeLink( [ 'do' => 'base.overlayLauncher', 'pagePath' => $path ], true ) );

            $this->assertNull( parse_url( $link, PHP_URL_FRAGMENT ), $path . ': the link was cut short' );

            parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

            $this->assertSame( $path, $query['pagePath'] ?? null, $path );
            $this->assertSame( 'base.overlayLauncher', $query['do'] ?? null, $path );
            $this->assertCount( 2, $query, $path . ': the value added parameters' );
        }
    }

    /** A link template's %s placeholder is left for the JavaScript to fill. */
    public function testALinkTemplatePlaceholderIsLeftAsItIs(): void
    {
        $t = new \OWA\Core\Template();

        $link = html_entity_decode( $t->makeLink( [ 'do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s' ] ) );

        $this->assertStringContainsString( 'pagePath=%s', $link );
        $this->assertStringNotContainsString( '%25s', $link );
    }
}
