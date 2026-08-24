<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA does not fetch referring web pages.
 *
 * It used to: a new referer caused the server to issue an HTTP GET to the
 * referring URL and scrape the page's <title> and anchor text into the referer
 * dimension.
 *
 * That URL arrives on the tracking beacon, which is public and unauthenticated
 * by design, and nothing constrained where it pointed -- so any anonymous
 * request could make the server fetch an arbitrary address, redirects and all,
 * and the extracted title then surfaced in reports as referralPageTitle. It
 * bought one piece of data the browser will never hand over (document.referrer
 * gives the URL and nothing else; the History API exposes no titles) at the
 * cost of turning every install into an HTTP client for anonymous input.
 *
 * Asserted absent rather than simply deleted, so the removal is a decision this
 * suite records and enforces.
 */
final class RefererCrawlRemovedTest extends TestCase
{
    /** @dataProvider goneProvider */
    public function testTheCrawlerIsGone( string $needle, string $file, string $why ): void
    {
        $path = OWA_DIR . $file;

        $this->assertFileExists( $path );

        $this->assertStringNotContainsString( $needle, (string) file_get_contents( $path ), $why );
    }

    public static function goneProvider(): array
    {
        return array(
            'the entity method' => array(
                'function crawlReferer',
                'modules/Base/Entity/Referer.php',
                'the referer entity can still fetch its own url',
            ),
            'the handler call' => array(
                'crawlReferer',
                'modules/Base/Handler/RefererHandlers.php',
                'a new referer still triggers a fetch',
            ),
            'the setting' => array(
                'fetch_refering_page_info',
                'modules/Base/Classes/Settings.php',
                'the setting survives, so something still reads it',
            ),
            'the options control' => array(
                'fetch_refering_page_info',
                'modules/Base/templates/options_general.php',
                'the options screen offers a setting that no longer exists',
            ),
        );
    }

    /** The CLI command that crawled every stored referer is gone too. */
    public function testTheCrawlCommandIsGone(): void
    {
        foreach ( array(
            'modules/Base/Controller/CrawlReferralCli.php',
            'modules/Base/View/CrawlReferralCli.php',
        ) as $file ) {

            $this->assertFileDoesNotExist( OWA_DIR . $file );
        }

        $module = (string) file_get_contents( OWA_DIR . 'modules/Base/Module.php' );

        $this->assertStringNotContainsString( 'update-referral', $module,
            'the CLI command is still registered, so it resolves to a missing class' );

        $this->assertStringNotContainsString( 'crawlReferralCli', $module,
            'the action is still registered' );
    }

    /**
     * Nothing anywhere still calls it.
     *
     * The per-file assertions above name the places it lived; this catches a
     * caller somewhere nobody thought to look.
     */
    public function testNothingCallsTheCrawler(): void
    {
        $callers = array();

        $dirs = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( OWA_DIR . 'modules' ) );

        foreach ( $dirs as $file ) {

            if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
                continue;
            }

            if ( strpos( (string) file_get_contents( $file->getPathname() ), 'crawlReferer' ) !== false ) {

                $callers[] = str_replace( OWA_DIR, '', $file->getPathname() );
            }
        }

        $this->assertSame( array(), $callers );
    }
}
