<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AutoUpdate\Releases;
use OWA\Module\Base\Classes\AutoUpdate\Staging;
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
            Releases::$fetcher = static function ($url, $timeout, $sink) use ($token) {
                $client = new \GuzzleHttp\Client(['timeout' => $timeout, 'allow_redirects' => false]);

                for ($hops = 0; $hops < 5; $hops++) {
                    $host = (string) parse_url($url, PHP_URL_HOST);
                    $r    = $client->request('GET', $url, array_filter([
                        'headers' => ['User-Agent' => 'owa-ci', 'Accept' => 'application/vnd.github+json, */*;q=0.1']
                            + ($host === 'api.github.com' ? ['Authorization' => 'Bearer ' . $token] : []),
                        'sink'    => $sink,
                    ]));

                    if ($r->getStatusCode() < 300 || $r->getStatusCode() >= 400) {
                        return $sink === null ? (string) $r->getBody() : '';
                    }
                    $url = $r->getHeaderLine('Location');
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

    /** The newest 1.14 patch, downloaded and its SHA-256 proved: 1.x releases are not signed, so that is where it stops. */
    public function testADownloadMatchesItsDigest(): void
    {
        $list    = Releases::fetch((string) \OWA\Core\CoreAPI::getSetting('base', 'auto_update_releases_url'));
        $release = Releases::choose($list, '1.14.0', 'patch');

        $this->assertNotNull($release);
        $this->assertSame('patch', $release['step']);
        $this->assertNotNull($release['sha256']);

        try {
            (new Staging(null, $this->tmp))->download($release);
            $this->fail('a 1.x release is not signed');
        } catch (\RuntimeException $e) {
            $this->assertSame(sprintf('owa_%1$s_packaged.tar is not signed: the release has no owa_%1$s_packaged.tar.sig.', $release['version']), $e->getMessage(),
                'downloaded, and its SHA-256 matched: the signature is checked after');
        }

        $this->assertSame([], glob($this->tmp . '/updates-*/staged*'), 'a failed staging is deleted');
        $this->assertSame([], glob(\OWA\Core\Env::dataDir() . 'updates-*'), 'nothing made in OWA\'s own data directory');
    }
}
