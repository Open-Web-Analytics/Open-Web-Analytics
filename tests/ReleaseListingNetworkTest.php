<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AutoUpdate\Releases;
use OWA\Module\Base\Classes\AutoUpdate\Downloads;
use OWA\Module\Base\Classes\AutoUpdate\Version;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The real GitHub releases listing, held to what the release check relies on:
 * tags that are versions, the tarball asset by its name, its SHA-256 digest,
 * and a download that matches it. An installed release finds the next one by
 * these, so a change to how releases are published fails here first.
 *
 * Needs the network: runs when OWA_TEST_NETWORK is set (CI's own step), with
 * GITHUB_TOKEN when there is one, since runners share the anonymous rate limit.
 */
final class ReleaseListingNetworkTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!getenv('OWA_TEST_NETWORK')) {
            $this->markTestSkipped('needs the network: set OWA_TEST_NETWORK=1');
        }

        $token = (string) getenv('GITHUB_TOKEN');

        if ($token !== '') {
            // Redirects followed here, so the token goes to api.github.com
            // behind the update host and nowhere else.
            // The user agent OWA sends: the update host's WAF lets only that through.
            $agent = (string) \OWA\Core\CoreAPI::getSetting('base', 'owa_user_agent');
            Releases::$fetcher = static function ($url, $timeout, $sink) use ($token, $agent) {
                $client = new \GuzzleHttp\Client(['timeout' => $timeout, 'allow_redirects' => false]);
                // As Releases::get(): one stream, emptied for each response, the handle left open.
                $body = $sink === null ? null : new \GuzzleHttp\Psr7\Stream($sink);

                try {
                    for ($hops = 0; $hops < 5; $hops++) {
                        if ($sink !== null) {
                            ftruncate($sink, 0);
                            rewind($sink);
                        }
                        $host = (string) parse_url($url, PHP_URL_HOST);
                        $r    = $client->request('GET', $url, array_filter([
                            'headers' => ['User-Agent' => $agent, 'Accept' => 'application/vnd.github+json, */*;q=0.1']
                                + ($host === 'api.github.com' ? ['Authorization' => 'Bearer ' . $token] : []),
                            'sink'    => $body,
                        ]));

                        if ($r->getStatusCode() < 300 || $r->getStatusCode() >= 400) {
                            return $sink === null ? (string) $r->getBody() : '';
                        }
                        $url = $r->getHeaderLine('Location');
                    }
                } finally {
                    $body?->detach();
                }

                throw new \RuntimeException("Fetching $url failed: too many redirects");
            };
        }

        $this->tmp = sys_get_temp_dir() . '/owa-listing-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', '');
    }

    protected function tearDown(): void
    {
        Releases::$fetcher = null;

        if ($this->tmp !== '') {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testTheListingKeepsTheContract(): void
    {
        $list = Releases::fetch((string) \OWA\Core\CoreAPI::getSetting('base', 'auto_update_releases_url'));
        $this->assertNotEmpty($list);

        $checked = 0;

        foreach ($list as $r) {
            $v = (string) ($r['tag_name'] ?? '');

            // Since 1.14.6 every release is published by the Release Packager as it is now.
            if (!empty($r['draft']) || Version::parse($v) === null || Version::compare($v, '1.14.6') < 0) {
                continue;
            }

            $asset = array_values(array_filter((array) $r['assets'], static fn ($a) => $a['name'] === sprintf(Releases::ASSET, $v)));
            $this->assertCount(1, $asset, "$v ships its tarball");
            $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', (string) ($asset[0]['digest'] ?? ''), "$v has its digest");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * The newest 1.14 patch, through the production client and the update
     * host's redirects: downloaded, and its SHA-256 GitHub's digest. 1.x
     * releases are not signed, so Downloads refuses it before downloading
     * anything: a release that can never install is never fetched.
     */
    public function testADownloadMatchesItsDigest(): void
    {
        $list    = Releases::fetch((string) \OWA\Core\CoreAPI::getSetting('base', 'auto_update_releases_url'));
        $release = Releases::choose($list, '1.14.0', 'patch');

        $this->assertNotNull($release);
        $this->assertSame('patch', $release['step']);
        $this->assertNotNull($release['sha256']);

        $tar = $this->tmp . '/owa_' . $release['version'] . '_packaged.tar';
        // Through the update host's /download/, which redirects to GitHub, as every download goes.
        $via = Releases::via($release, (string) \OWA\Core\CoreAPI::getSetting('base', 'auto_update_download_url'));
        $this->assertStringStartsWith('https://updates.openwebanalytics.com/download/', $via['asset_url']);
        $sink = fopen($tar, 'xb');
        Releases::get($via['asset_url'], Downloads::DOWNLOAD_TIMEOUT, $sink);
        fclose($sink);
        $this->assertSame($release['sha256'], hash_file('sha256', $tar), 'the file the listing names');
        unlink($tar);

        try {
            (new Downloads(null, $this->tmp))->download($release);
            $this->fail('a 1.x release is not signed');
        } catch (\RuntimeException $e) {
            $this->assertSame(sprintf('owa_%1$s_packaged.tar is not signed: the release has no owa_%1$s_packaged.tar.sig.', $release['version']), $e->getMessage());
        }

        $this->assertSame([], glob($this->tmp . '/updates-*/owa_*'), 'nothing downloaded, nothing kept');
        $this->assertSame([], glob(\OWA\Core\Env::dataDir() . 'updates-*'), 'nothing made in OWA\'s own data directory');
    }
}
