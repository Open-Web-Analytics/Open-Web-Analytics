<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Added to a phone's Home Screen, OWA opens as an app with its own name and
 * icon: every page head names the manifest and the iOS icon, and both exist.
 */
final class HomeScreenAppTest extends TestCase
{
    private function head(): string
    {
        $t = new \OWA\Core\Template();
        $t->set_template( 'head.php' );

        return (string) $t->fetch();
    }

    public function testTheHeadNamesTheManifestAndTheIcon(): void
    {
        $head = $this->head();

        $this->assertMatchesRegularExpression( '#<link rel="manifest" href="[^"]*base/i/manifest\.json">#', $head );
        $this->assertMatchesRegularExpression( '#<link rel="apple-touch-icon" href="[^"]*base/i/owa-app-icon-180\.png">#', $head );
        $this->assertStringContainsString( '<meta name="apple-mobile-web-app-capable" content="yes">', $head );
        $this->assertStringContainsString( '<meta name="apple-mobile-web-app-title" content="OWA">', $head );
    }

    public function testTheManifestOpensTheInstallAsAnApp(): void
    {
        $dir      = dirname( __DIR__ ) . '/modules/Base/i/';
        $manifest = json_decode( (string) file_get_contents( $dir . 'manifest.json' ), true );

        $this->assertSame( 'standalone', $manifest['display'] ?? null );
        $this->assertSame( '../../../index.php', $manifest['start_url'] ?? null,
            'public/base/i/ is three levels below the install root' );

        foreach ( $manifest['icons'] as $icon ) {
            $this->assertFileExists( $dir . $icon['src'] );
        }

        $this->assertFileExists( $dir . 'owa-app-icon-180.png' );
        $this->assertSame( array( 180, 180 ), array_slice( getimagesize( $dir . 'owa-app-icon-180.png' ), 0, 2 ) );
    }
}
