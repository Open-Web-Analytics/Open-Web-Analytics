<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A recording is played by opening the page it was recorded on, so only a web
 * page gets a Play link.
 *
 * The page is the location the beacon claimed, and anyone can send a beacon.
 * A recording whose location was javascript: had a Play link that ran script
 * in OWA's own window when a reader clicked it.
 */
final class DomstreamPlayLinkTest extends TestCase
{
    private function playUrl( string $location ): string
    {
        $controller = ( new ReflectionClass( \OWA\Module\Domstream\Controller\ReportDomstreams::class ) )
            ->newInstanceWithoutConstructor();

        $m = new ReflectionMethod( $controller, 'playerPayload' );
        $m->setAccessible( true );

        return (string) $m->invoke( $controller, array(
            'recording_id'  => '4921417228',
            'site_id'       => 'owa-test-site',
            'page_location' => $location,
            'viewport_w'    => 1280,
            'viewport_h'    => 800,
        ) )['url'];
    }

    public function testAWebPageIsPlayedWhereItWasRecorded(): void
    {
        $this->assertSame( 'https://example.test/pricing', $this->playUrl( 'https://example.test/pricing#top' ) );
        $this->assertSame( 'http://example.test/', $this->playUrl( 'http://example.test/' ) );
    }

    /** @return array<string, array{string}> */
    public static function notPages(): array
    {
        return array(
            'javascript'        => array( 'javascript:alert(1)' ),
            'mixed case'        => array( 'JaVaScRiPt:alert(1)' ),
            'data'              => array( 'data:text/html,<script>1</script>' ),
            'no scheme'         => array( 'example.test/pricing' ),
            'protocol relative' => array( '//example.test/pricing' ),
            'empty'             => array( '' ),
        );
    }

    /**
     * @dataProvider notPages
     */
    public function testAnythingElseHasNoPlayLink( string $location ): void
    {
        $this->assertSame( '', $this->playUrl( $location ) );
    }
}
