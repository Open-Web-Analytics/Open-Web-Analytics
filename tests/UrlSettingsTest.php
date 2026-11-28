<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/BootsWithConstants.php';

/**
 * The three URLs an install is reached at: OWA_PUBLIC_URL (screens, API,
 * links), OWA_TRACKING_URL (log.php) and OWA_ASSETS_URL (public/, a CDN). The
 * last two are Settings::trackingUrl() and assetsUrl(): the constant when it
 * is set, else derived from public_url when read, so they follow a public_url
 * set after boot. public_url is given its trailing slash at boot (setupPaths()).
 */
final class UrlSettingsTest extends TestCase
{
    use BootsWithConstants;

    /** A fresh Settings, so nothing here disturbs the booted one. */
    private function settings(array $set): \OWA\Module\Base\Classes\Settings
    {
        $s = new \OWA\Module\Base\Classes\Settings();

        foreach ($set + ['tracking_url' => '', 'assets_url' => ''] as $key => $value) {
            $s->set('base', $key, $value);
        }

        return $s;
    }

    public function testByDefaultBothAreUnderThePublicUrl(): void
    {
        $s = $this->settings(['public_url' => 'https://owa.example.test/']);

        $this->assertSame('https://owa.example.test/', $s->trackingUrl());
        $this->assertSame('https://owa.example.test/public/', $s->assetsUrl());
    }

    /** Derived when read: a public_url set later (the installer, a test) is followed by both. */
    public function testBothFollowAPublicUrlSetAfterBoot(): void
    {
        $s = $this->settings(['public_url' => 'https://owa.example.test/']);
        $s->trackingUrl();
        $s->assetsUrl();

        $s->set('base', 'public_url', 'https://elsewhere.example.test/owa');

        $this->assertSame('https://elsewhere.example.test/owa/', $s->trackingUrl());
        $this->assertSame('https://elsewhere.example.test/owa/public/', $s->assetsUrl());
    }

    /**
     * And the booted install's, through the singleton every reader uses.
     *
     * @covers-setting base.public_url
     */
    public function testTheBootedInstallFollowsItsPublicUrl(): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = [];

        foreach (['public_url', 'tracking_url', 'assets_url'] as $key) {
            $saved[$key] = $c->get('base', $key);
        }

        try {
            $c->set('base', 'tracking_url', '');
            $c->set('base', 'assets_url', '');
            $c->set('base', 'public_url', 'https://owa.example.test/owa/');

            $this->assertSame('https://owa.example.test/owa/', $c->trackingUrl());
            $this->assertSame('https://owa.example.test/owa/public/', $c->assetsUrl());
            $this->assertSame('https://owa.example.test/owa/public/tracker/zz-url-test.js',
                \OWA\Module\Base\Classes\TrackerBundle::url('zz-url-test'));
            $this->assertSame('https://owa.example.test/owa/public/base/i/x.png', (new \OWA\Core\Template())->makeImageLink('base/i/x.png'));
        } finally {
            foreach ($saved as $key => $value) {
                $c->set('base', $key, $value);
            }
        }
    }

    public function testATrackingUrlIsWhereLogPhpIs(): void
    {
        $s = $this->settings(['public_url' => 'https://owa.example.test/', 'tracking_url' => 'https://t.example.test']);

        $this->assertSame('https://t.example.test/', $s->trackingUrl(), 'a slash is added');
        $this->assertSame('https://owa.example.test/public/', $s->assetsUrl());
    }

    /**
     * @covers-setting base.assets_url
     */
    public function testAnAssetsUrlServesPublic(): void
    {
        $s = $this->settings(['public_url' => 'https://owa.example.test/', 'assets_url' => 'https://cdn.example.test/owa//']);

        $this->assertSame('https://cdn.example.test/owa/', $s->assetsUrl());
        $this->assertSame('https://owa.example.test/', $s->trackingUrl());
    }

    /** setupPaths() stores no derived value, so nothing can go stale. */
    public function testBootStoresNoDerivedUrl(): void
    {
        $s = $this->settings(['public_url' => 'https://owa.example.test/']);
        (new \ReflectionMethod($s, 'setupPaths'))->invoke($s);

        $this->assertSame('', (string) $s->get('base', 'tracking_url'));
        $this->assertSame('', (string) $s->get('base', 'assets_url'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function publicUrls(): iterable
    {
        yield 'without the slash' => ['https://owa.example.test/owa', 'https://owa.example.test/owa/'];
        yield 'with it'           => ['https://owa.example.test/owa/', 'https://owa.example.test/owa/'];
        yield 'with two'          => ['https://owa.example.test/owa//', 'https://owa.example.test/owa/'];
        yield 'none'              => ['', ''];
    }

    /**
     * @dataProvider publicUrls
     *
     * @covers-setting base.public_url
     */
    public function testThePublicUrlEndsInASlashAndSoDoesEveryUrlBuiltFromIt(string $given, string $public): void
    {
        $s = $this->settings(['public_url' => $given]);
        (new \ReflectionMethod($s, 'setupPaths'))->invoke($s);

        $this->assertSame($public, $s->get('base', 'public_url'));
        $this->assertSame($public . 'index.php', $s->get('base', 'main_url'));
        $this->assertSame($public . 'api/index.php', $s->get('base', 'rest_api_url'));
        $this->assertSame($public . 'modules/', $s->get('base', 'modules_url'));
        $this->assertSame($public, $s->trackingUrl());
    }

    /**
     * @covers-setting base.public_url
     */
    public function testOwaPublicUrlWithoutItsSlashGetsOneAtBoot(): void
    {
        $this->assertSame([
            'base.public_url'   => ['value' => 'https://owa.example.test/owa/', 'constant' => 'OWA_PUBLIC_URL'],
            'base.main_url'     => ['value' => 'https://owa.example.test/owa/index.php', 'constant' => ''],
            'base.rest_api_url' => ['value' => 'https://owa.example.test/owa/api/index.php', 'constant' => ''],
        ], $this->bootWith(['OWA_PUBLIC_URL' => 'https://owa.example.test/owa'], 'base.public_url', 'base.main_url', 'base.rest_api_url'));
    }

    /** Both are governed by their constants, which a boot applies and names (fixtures/settings_from_constants_probe.php). */
    public function testBothAreConfigFileConstants(): void
    {
        $this->assertSame([
            'base.tracking_url' => ['value' => 'https://t.example.test/owa/', 'constant' => 'OWA_TRACKING_URL'],
            'base.assets_url'   => ['value' => 'https://cdn.example.test/owa/', 'constant' => 'OWA_ASSETS_URL'],
        ], $this->bootWith(
            ['OWA_TRACKING_URL' => 'https://t.example.test/owa/', 'OWA_ASSETS_URL' => 'https://cdn.example.test/owa/'],
            'base.tracking_url', 'base.assets_url'));
    }
}
