<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Built stylesheets and scripts are requested with ?version=, and a browser
 * keeps what it has for that URL. OWA_VERSION is 'master' in a source checkout,
 * so the URL has to change with the build, not with the release.
 */
final class AssetVersionTest extends TestCase
{
    private string $dir;
    private $publicPath;

    protected function setUp(): void
    {
        $this->publicPath = \OWA\Core\CoreAPI::getSetting( 'base', 'public_path' );
        $this->dir = sys_get_temp_dir() . '/owa-asset-version-' . getmypid() . '/';
        @mkdir( $this->dir . 'base/css', 0777, true );
        \OWA\Core\CoreAPI::setSetting( 'base', 'public_path', $this->dir );
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting( 'base', 'public_path', $this->publicPath );
        @unlink( $this->dir . 'base/css/sample.css' );
        @rmdir( $this->dir . 'base/css' );
        @rmdir( $this->dir . 'base' );
        @rmdir( $this->dir );
    }

    public function testARebuiltFileGetsANewUrl(): void
    {
        $file = $this->dir . 'base/css/sample.css';
        file_put_contents( $file, 'a{}' );
        touch( $file, 1700000000 );

        $view = new \OWA\Core\View();
        $view->setCss( 'base/css/sample.css' );
        $before = $view->css['base/css/sample.css']['url'];

        touch( $file, 1700000600 );
        clearstatcache();

        $view = new \OWA\Core\View();
        $view->setCss( 'base/css/sample.css' );
        $after = $view->css['base/css/sample.css']['url'];

        $this->assertStringEndsWith( '?version=' . OWA_VERSION . '.1700000000', $before );
        $this->assertNotSame( $before, $after, 'A rebuilt stylesheet kept its URL, so a browser keeps the old one.' );
    }

    public function testScriptsAreVersionedTheSameWay(): void
    {
        $file = $this->dir . 'base/css/sample.css';
        file_put_contents( $file, 'a{}' );
        touch( $file, 1700000000 );

        $view = new \OWA\Core\View();
        $view->setJs( 'sample', 'base/css/sample.css' );

        $this->assertStringEndsWith( '?version=' . OWA_VERSION . '.1700000000',
            current( array_filter( $view->js, fn( $js ) => str_contains( $js['url'], 'sample.css' ) ) )['url'] );
    }

    public function testAMissingFileFallsBackToTheRelease(): void
    {
        $this->assertSame( OWA_VERSION, \OWA\Core\View::assetVersion( 'base/css/not-built.css' ) );
    }

    public function testAnExplicitVersionIsKept(): void
    {
        $view = new \OWA\Core\View();
        $view->setCss( 'base/css/sample.css', '9.9' );

        $this->assertStringEndsWith( '?version=9.9', $view->css['base/css/sample.css']['url'] );
    }
}
