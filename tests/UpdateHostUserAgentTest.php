<?php

use OWA\Module\Base\Classes\AutoUpdate\Releases;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The User-Agent of requests to the update host: fixed, built by
 * Releases::userAgent(), and sent by Releases::get(), through which every one
 * of those requests goes. ReleasesGetTest checks the header on the wire.
 */
final class UpdateHostUserAgentTest extends TestCase
{
    public function testTheUserAgentNamesTheVersionAndTheUpdaterPage(): void
    {
        $this->assertSame('OpenWebAnalytics/' . \OWA\Core\Env::version() . ' (+https://updates.openwebanalytics.com/updater)',
            Releases::userAgent());
    }

    /** owa_user_agent is gone, and OWA_USER_AGENT gets the retired constant's notice. */
    public function testTheSettingIsGone(): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', 'owa_user_agent'));
        $this->assertArrayHasKey('OWA_USER_AGENT', \OWA\Core\Requirements::RETIRED_CONSTANTS);
    }

    /**
     * The automatic update code makes no HTTP request but through
     * Releases::get(): the listing (Releases::fetch()), each tarball, its
     * signature and SHA256SUMS (Downloads::download()).
     */
    public function testEveryUpdateHostRequestGoesThroughGet(): void
    {
        $root  = \OWA\Core\Env::root();
        $files = array_merge(glob($root . '/modules/Base/Classes/AutoUpdate/*.php') ?: [], [$root . '/updater.php'],
            glob($root . '/modules/Base/Controller/CoreUpdate*.php') ?: [], [$root . '/modules/Base/Controller/CoreInstallCli.php']);

        $this->assertGreaterThan(10, count($files));

        $found = [];

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);

            foreach (['GuzzleHttp\\Client', 'stream_context_create', 'curl_init', 'fsockopen', "fopen( 'http", "file_get_contents( 'http"] as $call) {
                if (strpos($src, $call) !== false) {
                    $found[] = basename($file) . ': ' . $call;
                }
            }
        }

        $this->assertSame(['Releases.php: GuzzleHttp\\Client'], $found);
        $this->assertSame(1, substr_count((string) file_get_contents($root . '/modules/Base/Classes/AutoUpdate/Releases.php'), 'new \\GuzzleHttp\\Client('));

        $downloads = (string) file_get_contents($root . '/modules/Base/Classes/AutoUpdate/Downloads.php');
        $this->assertSame(3, substr_count($downloads, 'Releases::get( $release['), 'SHA256SUMS, the tarball and its signature');
        $this->assertStringContainsString("'User-Agent' => self::userAgent(),",
            (string) file_get_contents($root . '/modules/Base/Classes/AutoUpdate/Releases.php'));
    }
}
