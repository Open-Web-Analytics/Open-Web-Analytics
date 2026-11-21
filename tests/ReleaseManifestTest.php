<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\ReleaseManifest;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * manifest.json (Core\ReleaseManifest): every file a release ships, and how
 * a tree differs from it.
 */
final class ReleaseManifestTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-manifest-' . bin2hex(random_bytes(4));

        mkdir($this->dir . '/Core', 0755, true);
        mkdir($this->dir . '/owa-data/logs', 0755, true);
        file_put_contents($this->dir . '/cli.php', '<?php // cli');
        chmod($this->dir . '/cli.php', 0700);
        file_put_contents($this->dir . '/Core/A.php', 'a');
        file_put_contents($this->dir . '/owa-config.php', 'secret');
        file_put_contents($this->dir . '/owa-data/logs/x.txt', 'log');
        file_put_contents($this->dir . '/manifest.json', '{}');
        symlink('Core/A.php', $this->dir . '/link.php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testItListsWhatAReleaseShips(): void
    {
        $m = ReleaseManifest::build($this->dir);

        $this->assertSame(1, $m['format']);
        $this->assertSame(['Core/A.php', 'cli.php', 'link.php'], array_keys($m['files']),
            'sorted; not itself, owa-config.php or owa-data/');
        $this->assertSame(['sha256' => hash('sha256', 'a'), 'size' => 1, 'mode' => '0644'], $m['files']['Core/A.php']);
        $this->assertSame('0700', $m['files']['cli.php']['mode']);
        $this->assertSame('Core/A.php', $m['files']['link.php']['link'], 'a link by its target, not followed');
    }

    public function testATreeIsComparedByContentNotMode(): void
    {
        $m = ReleaseManifest::build($this->dir);

        $this->assertSame(['missing' => [], 'changed' => [], 'unlisted' => []], ReleaseManifest::compare($m, $this->dir));

        chmod($this->dir . '/cli.php', 0755);
        $this->assertSame([], ReleaseManifest::compare($m, $this->dir)['changed'], 'an install keeps its own modes');

        file_put_contents($this->dir . '/Core/A.php', 'b');
        unlink($this->dir . '/link.php');
        file_put_contents($this->dir . '/Core/New.php', 'n');

        $this->assertSame([
            'missing'  => ['link.php'],
            'changed'  => ['Core/A.php'],
            'unlisted' => ['Core/New.php'],
        ], ReleaseManifest::compare($m, $this->dir));
    }

    /** cmd=release-manifest lists this checkout, as the packager runs it in the unpacked tarball. */
    public function testTheCommandListsTheCheckout(): void
    {
        $file = $this->dir . '/out.json';

        ob_start();
        $code = \OWA\Module\Base\Controller\ReleaseManifestCli::runWithoutBoot(['cmd' => 'release-manifest', 'file' => $file]);
        ob_end_clean();

        $this->assertSame(0, $code);

        $files = json_decode((string) file_get_contents($file), true)['files'];

        $this->assertArrayHasKey('cli.php', $files);
        $this->assertArrayHasKey('Core/ReleaseManifest.php', $files);
        $this->assertArrayNotHasKey('owa-config.php', $files);
        $this->assertSame(hash_file('sha256', \OWA\Core\Env::root() . '/cli.php'), $files['cli.php']['sha256']);
    }
}
