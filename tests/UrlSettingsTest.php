<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The three URLs an install is reached at: OWA_PUBLIC_URL (screens, API,
 * links), OWA_TRACKING_URL (log.php) and OWA_ASSETS_URL (public/, a CDN). The
 * last two default to the first's, and setupPaths() derives log_url and the
 * image URLs from them.
 *
 * setupPaths() is private and runs during construction, so these drive the
 * real method by reflection on a fresh Settings (see ConfigFileLogPathsTest).
 */
final class UrlSettingsTest extends TestCase
{
    private function paths(array $set): \OWA\Module\Base\Classes\Settings
    {
        $s = new \OWA\Module\Base\Classes\Settings();

        foreach ($set + array('tracking_url' => '', 'assets_url' => '') as $key => $value) {
            $s->set('base', $key, $value);
        }

        (new \ReflectionMethod($s, 'setupPaths'))->invoke($s);

        return $s;
    }

    public function testByDefaultEverythingIsUnderThePublicUrl(): void
    {
        $s = $this->paths(array('public_url' => 'https://owa.example.test/'));

        $this->assertSame('https://owa.example.test/', $s->get('base', 'tracking_url'));
        $this->assertSame('https://owa.example.test/log.php', $s->get('base', 'log_url'));
        $this->assertSame('https://owa.example.test/public/', $s->get('base', 'assets_url'));
        $this->assertSame('https://owa.example.test/public/', $s->get('base', 'images_url'));
        $this->assertSame('https://owa.example.test/api/index.php', $s->get('base', 'rest_api_url'), 'the API stays on the public URL');
    }

    public function testATrackingUrlIsWhereLogPhpIs(): void
    {
        $s = $this->paths(array('public_url' => 'https://owa.example.test/', 'tracking_url' => 'https://t.example.test'));

        $this->assertSame('https://t.example.test/', $s->get('base', 'tracking_url'), 'a slash is added');
        $this->assertSame('https://t.example.test/log.php', $s->get('base', 'log_url'));
        $this->assertSame('https://owa.example.test/public/', $s->get('base', 'assets_url'));
    }

    public function testAnAssetsUrlServesPublic(): void
    {
        $s = $this->paths(array('public_url' => 'https://owa.example.test/', 'assets_url' => 'https://cdn.example.test/owa//'));

        $this->assertSame('https://cdn.example.test/owa/', $s->get('base', 'assets_url'));
        $this->assertSame('https://cdn.example.test/owa/', $s->get('base', 'images_url'));
        $this->assertSame('https://cdn.example.test/owa/', $s->get('base', 'images_absolute_url'));
        $this->assertSame('https://owa.example.test/log.php', $s->get('base', 'log_url'));
    }

    /** Both are governed by their constants: applyConfigConstants() names them, so the wiki lists them. */
    public function testBothAreConfigFileConstants(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/Classes/Settings.php');

        $this->assertStringContainsString("setFromConfigConstant( 'base', 'tracking_url', (string) OWA_TRACKING_URL, 'OWA_TRACKING_URL')", $source);
        $this->assertStringContainsString("setFromConfigConstant( 'base', 'assets_url', (string) OWA_ASSETS_URL, 'OWA_ASSETS_URL')", $source);
    }
}
