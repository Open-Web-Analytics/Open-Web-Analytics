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
    private function render(array $options = array()): string
    {
        $t = new \OWA\Core\Template();
        $t->set( 'site_id', 'snippet-site' );
        $t->set( 'cmds', array() );
        $t->set( 'options', $options );
        $t->set_template( 'js_log_tag.php' );

        return $t->fetch();
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
        $this->assertStringContainsString( "setSiteId', 'snippet-site'", $html );
        $this->assertStringContainsString( 'public/base/dist/owa.tracker.js', $html );
    }
}
