<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA does not fetch referring web pages.
 *
 * The referer URL arrives on the tracking beacon, which is public and
 * unauthenticated by design, so fetching it would let any anonymous request
 * make the server fetch an arbitrary address, redirects and all -- for one
 * piece of data the browser never hands over (a page's title;
 * document.referrer gives the URL and nothing else).
 *
 * Asserted absent, so the decision is one this suite records and enforces.
 */
final class RefererCrawlRemovedTest extends TestCase
{
    /** @dataProvider goneProvider */
    public function testTheCrawlerIsGone( string $needle, string $file, string $why ): void
    {
        $path = \OWA\Core\Env::root() . '/' . $file;

        $this->assertFileExists( $path );

        $this->assertStringNotContainsString( $needle, (string) file_get_contents( $path ), $why );
    }

    public static function goneProvider(): array
    {
        return [
            'the setting' => [
                'fetch_refering_page_info',
                'modules/Base/Classes/Settings.php',
                'the setting survives, so something still reads it',
            ],
            'the options control' => [
                'fetch_refering_page_info',
                'modules/Base/templates/options_general.php',
                'the options screen offers a setting that no longer exists',
            ],
        ];
    }

    /** No CLI command crawls the stored referers either. */
    public function testTheCrawlCommandIsGone(): void
    {
        foreach ( [
            'modules/Base/Controller/CrawlReferralCli.php',
            'modules/Base/View/CrawlReferralCli.php',
        ] as $file ) {

            $this->assertFileDoesNotExist( \OWA\Core\Env::root() . '/' . $file );
        }

        $module = (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/Module.php' );

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
        $callers = [];

        $dirs = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( \OWA\Core\Env::root() . '/modules' ) );

        foreach ( $dirs as $file ) {

            if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
                continue;
            }

            if ( strpos( (string) file_get_contents( $file->getPathname() ), 'crawlReferer' ) !== false ) {

                $callers[] = str_replace( \OWA\Core\Env::root() . '/', '', $file->getPathname() );
            }
        }

        $this->assertSame( [], $callers );
    }
}
