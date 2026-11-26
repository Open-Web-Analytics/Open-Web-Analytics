<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\PrivateFiles;

require_once __DIR__ . '/bootstrap_owa.php';

/** The install's hashed file names, and the index.php every directory OWA makes is given. */
final class PrivateFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-private-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** The error log's default name is checked in DataDirConfigTest, where the runner's config cannot pin it. */
    public function testANameCarriesTheInstallsHash(): void
    {
        $hash = \OWA\Core\CoreAPI::generateInstanceSpecificHash();

        $this->assertSame('events_' . $hash . '.txt', PrivateFiles::name('events', '.txt'));
    }

    public function testGuardWritesTheShippedIndexOnceAndNeverThroughALink(): void
    {
        $this->assertTrue(PrivateFiles::guard($this->dir));
        $this->assertSame(file_get_contents(__DIR__ . '/../owa-data/index.php'), file_get_contents($this->dir . 'index.php'));

        file_put_contents($this->dir . 'index.php', 'kept');
        $this->assertTrue(PrivateFiles::guard($this->dir));
        $this->assertSame('kept', file_get_contents($this->dir . 'index.php'), 'one there is left');

        mkdir($this->dir . 'linked');
        symlink($this->dir . 'target.php', $this->dir . 'linked/index.php');
        $this->assertFalse(PrivateFiles::guard($this->dir . 'linked'));
        $this->assertFileDoesNotExist($this->dir . 'target.php');
    }

    public function testEveryDirectoryOwaMakesIsGuarded(): void
    {
        $this->assertTrue(\OWA\Core\Lib::makeSharedDir($this->dir . 'a/b'));
        $this->assertTrue(\OWA\Core\SafeFile::makeDirs($this->dir . 'c/d', 0755));

        foreach (['a', 'a/b', 'c', 'c/d'] as $made) {
            $this->assertFileExists($this->dir . $made . '/index.php', $made);
        }
    }
}
