<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The three URLs an install is reached at: OWA_PUBLIC_URL (screens, API,
 * links), OWA_TRACKING_URL (log.php) and OWA_ASSETS_URL (public/, a CDN). The
 * last two are Settings::trackingUrl() and assetsUrl(): the constant when it
 * is set, else derived from public_url when read, so they follow a public_url
 * set after boot.
 */
final class UrlSettingsTest extends TestCase
{
    /** A fresh Settings, so nothing here disturbs the booted one. */
    private function settings(array $set): \OWA\Module\Base\Classes\Settings
    {
        $s = new \OWA\Module\Base\Classes\Settings();

        foreach ($set + array('tracking_url' => '', 'assets_url' => '') as $key => $value) {
            $s->set('base', $key, $value);
        }

        return $s;
    }

    public function testByDefaultBothAreUnderThePublicUrl(): void
    {
        $s = $this->settings(array('public_url' => 'https://owa.example.test/'));

        $this->assertSame('https://owa.example.test/', $s->trackingUrl());
        $this->assertSame('https://owa.example.test/public/', $s->assetsUrl());
    }

    /** Derived when read: a public_url set later (the installer, a test) is followed by both. */
    public function testBothFollowAPublicUrlSetAfterBoot(): void
    {
        $s = $this->settings(array('public_url' => 'https://owa.example.test/'));
        $s->trackingUrl();
        $s->assetsUrl();

        $s->set('base', 'public_url', 'https://elsewhere.example.test/owa');

        $this->assertSame('https://elsewhere.example.test/owa/', $s->trackingUrl());
        $this->assertSame('https://elsewhere.example.test/owa/public/', $s->assetsUrl());
    }

    /** And the booted install's, through the singleton every reader uses. */
    public function testTheBootedInstallFollowsItsPublicUrl(): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = array();

        foreach (array('public_url', 'tracking_url', 'assets_url') as $key) {
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
        $s = $this->settings(array('public_url' => 'https://owa.example.test/', 'tracking_url' => 'https://t.example.test'));

        $this->assertSame('https://t.example.test/', $s->trackingUrl(), 'a slash is added');
        $this->assertSame('https://owa.example.test/public/', $s->assetsUrl());
    }

    public function testAnAssetsUrlServesPublic(): void
    {
        $s = $this->settings(array('public_url' => 'https://owa.example.test/', 'assets_url' => 'https://cdn.example.test/owa//'));

        $this->assertSame('https://cdn.example.test/owa/', $s->assetsUrl());
        $this->assertSame('https://owa.example.test/', $s->trackingUrl());
    }

    /** setupPaths() stores no derived value, so nothing can go stale. */
    public function testBootStoresNoDerivedUrl(): void
    {
        $s = $this->settings(array('public_url' => 'https://owa.example.test/'));
        (new \ReflectionMethod($s, 'setupPaths'))->invoke($s);

        $this->assertSame('', (string) $s->get('base', 'tracking_url'));
        $this->assertSame('', (string) $s->get('base', 'assets_url'));
    }

    /** Both are governed by their constants: applyConfigConstants() names them, so the wiki lists them. */
    public function testBothAreConfigFileConstants(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/Classes/Settings.php');

        $this->assertStringContainsString("setFromConfigConstant( 'base', 'tracking_url', (string) OWA_TRACKING_URL, 'OWA_TRACKING_URL')", $source);
        $this->assertStringContainsString("setFromConfigConstant( 'base', 'assets_url', (string) OWA_ASSETS_URL, 'OWA_ASSETS_URL')", $source);
    }
}
