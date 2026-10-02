<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The tracking snippet: what it asks the browser to do before it asks for code.
 *
 * This template is the one piece of OWA that runs on somebody else's page, in
 * markup a site owner pasted by hand and will never revisit. It gets copied into
 * CMS templates, tag managers and theme headers, and a broken one fails silently
 * -- there is no error anywhere OWA can see, only a site that stopped reporting.
 *
 * So the properties worth pinning are the ones that decide whether it works AT
 * ALL on a page nobody tested it against, and the ones that decide how long the
 * visitor waits before the first byte of tracker code exists.
 */
final class TrackerSnippetTest extends TestCase
{
    /** The classic tag, through the real path, so the Profile's commands are in it. */
    private function render(array $options = array()): string
    {
        return \OWA\Core\CoreAPI::getJsTrackerTag( 'snippet-site', $options );
    }

    public function testItPreconnectsToTheTrackerOriginBeforeAskingForTheScript(): void
    {
        $html = $this->render();

        $host = parse_url( \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), PHP_URL_HOST );
        $this->assertNotEmpty( $host, 'the fixture needs a public_url with a host to be meaningful' );

        $this->assertStringContainsString( 'rel="preconnect"', $html );
        $this->assertStringContainsString( $host, $html );

        // Order is the entire point. A preconnect the parser meets AFTER the
        // script element has already been requested has nothing left to
        // overlap with.
        $this->assertLessThan(
            strpos( $html, 'createElement' ),
            strpos( $html, 'rel="preconnect"' ),
            'the preconnect hint must appear before the loader that depends on it'
        );
    }

    public function testThePreconnectIsNotCrossoriginBecauseNothingItCoversIsCors(): void
    {
        $html = $this->render();

        $preconnect_tag = substr( $html, strpos( $html, '<link rel="preconnect"' ), 120 );

        // An anonymous preconnect opens a connection the script fetch and the
        // beacon can never reuse, so the hint costs a connection and saves
        // nothing. This is the most common way preconnect is got wrong.
        $this->assertStringNotContainsString( 'crossorigin', $preconnect_tag );
    }

    public function testThePreconnectFollowsThePageProtocol(): void
    {
        $html = $this->render();

        // The loader rewrites owa_baseUrl to https when the PAGE is https. A
        // hint hardcoded to the stored scheme would preconnect to an origin the
        // script fetch then does not use.
        $this->assertMatchesRegularExpression( '#<link rel="preconnect" href="//[^"]+">#', $html );
        $this->assertStringNotContainsString( 'href="http://', $html );
        $this->assertStringNotContainsString( 'href="https://', $html );
    }

    public function testNoLinkTagsAreEmittedWhenTheOutputIsPlainJavascript(): void
    {
        $js = $this->render( array( 'no_script_wrapper' => true ) );

        // With no_script_wrapper the caller has already opened a <script> block
        // and is pasting this inside it. A <link> tag there is not an ignored
        // hint -- it is a syntax error that kills the whole block, tracker
        // included.
        $this->assertStringNotContainsString( '<link', $js );
        $this->assertStringNotContainsString( '<script', $js );
        $this->assertStringContainsString( 'owa_cmds.push', $js );
    }

    public function testTheLoaderDoesNotDependOnAnotherScriptTagExisting(): void
    {
        $html = $this->render();

        // getElementsByTagName('script')[0] is undefined on a page whose only
        // script is external and first -- and the next line dereferences its
        // parentNode. That is a TypeError before the tracker is ever requested.
        $this->assertStringNotContainsString( "getElementsByTagName('script')[0]", $html );
        $this->assertStringNotContainsString( 'parentNode.insertBefore', $html );
    }

    public function testTheLoaderAppendsSomewhereThatAlwaysExists(): void
    {
        $html = $this->render();

        // documentElement is the last resort precisely because a document
        // without one is not a document at all.
        $this->assertStringContainsString( 'document.documentElement', $html );
        $this->assertStringContainsString( 'appendChild', $html );
    }

    public function testTheScriptIsStillAsync(): void
    {
        $html = $this->render();

        // The whole reason the insert-before-first-script dance can be dropped
        // is that async decides fetch behaviour now, not document position. If
        // async ever goes, the loader becomes render-blocking on every page
        // running OWA.
        $this->assertStringContainsString( '_owa.async = true', $html );
    }

    /**
     * Vacuity guard.
     *
     * Every assertion above is a substring check against rendered markup, and
     * substring checks against a template that failed to render pass or fail for
     * the wrong reason. Prove the render produced the snippet at all.
     */
    public function testTheFixtureActuallyRendersTheSnippet(): void
    {
        $html = $this->render();

        $this->assertStringContainsString( 'owa_cmds', $html );
        $this->assertStringContainsString( 'owa_cmds.push(["setSiteId","snippet-site"]);', $html );
        $this->assertStringContainsString( 'public/base/dist/owa.tracker.js', $html );
    }

    /**
     * The classic tag carries the Profile's commands: the ones its bundle would
     * bake in, from its tag settings (PLAN 2.24.4), not a list of its own.
     */
    public function testTheClassicTagCarriesTheProfilesCommands(): void
    {
        $html = $this->render();

        foreach ( \OWA\Module\Base\Classes\TrackerBundle::commandLines( 'snippet-site' ) as $line ) {
            $this->assertStringContainsString( $line, $html );
        }

        $this->assertStringContainsString( 'owa_cmds.push(["trackPageView"]);', $html );
        $this->assertStringContainsString( '"stateStoreExpirations"', $html );
    }

    private function bundleTag(): string
    {
        return \OWA\Core\CoreAPI::getJsTrackerBundleTag( 'snippet-site' );
    }

    /** The bundle tag is one async script for the Profile's bundle, protocol-relative. */
    public function testTheBundleTagLoadsTheProfilesBundle(): void
    {
        $html = $this->bundleTag();
        $url  = preg_replace( '#^https?:#', '', \OWA\Module\Base\Classes\TrackerBundle::url( 'snippet-site' ) );

        $this->assertStringContainsString( '<script async src="' . $url . '"></script>', $html );
        $this->assertSame( 2, substr_count( $html, '<script' ), 'the command queue and the bundle, nothing else' );
        $this->assertStringContainsString( '<script>var owa_cmds = owa_cmds || [];</script>', $html );
    }

    public function testTheBundleTagPreconnectsAndCarriesNoComments(): void
    {
        $html = $this->bundleTag();

        $this->assertMatchesRegularExpression( '#<link rel="preconnect" href="//[^"]+">#', $html );
        $this->assertStringNotContainsString( 'crossorigin', $html );
        $this->assertStringNotContainsString( '//<![CDATA[', $html );
        $this->assertSame( 2, substr_count( $html, '<!--' ), 'only the start and end markers' );
        $this->assertStringNotContainsString( '/*', $html );
    }
}
