<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\PrivateFiles;

require_once __DIR__ . '/bootstrap_owa.php';

/** The install's hashed file names, those under an earlier hash, and the index.php every directory OWA makes is given. */
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

    /** HMAC-SHA256 of 'owa-private-files' keyed with OWA_SECRET, its first 32 hex characters. */
    public function testTheHashIsAnHmacOfOwaSecret(): void
    {
        $hash = \OWA\Core\CoreAPI::privateFilesHash('a-secret');

        $this->assertSame(substr(hash_hmac('sha256', 'owa-private-files', 'a-secret'), 0, 32), $hash);
        $this->assertMatchesRegularExpression('/^' . PrivateFiles::HASH_PATTERN . '$/', $hash);
        $this->assertSame($hash, \OWA\Core\CoreAPI::privateFilesHash('a-secret'), 'the same secret, the same hash');
        $this->assertNotSame($hash, \OWA\Core\CoreAPI::privateFilesHash('another-secret'));
        $this->assertSame(\OWA\Core\CoreAPI::privateFilesHash(defined('OWA_SECRET') ? (string) constant('OWA_SECRET') : ''),
            \OWA\Core\CoreAPI::generateInstanceSpecificHash());
    }

    /** The hash a process with these constants defined computes. */
    private static function hashUnder(array $constants): string
    {
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';';

        foreach ($constants as $name => $value) {
            $code .= sprintf('define(%s, %s);', var_export($name, true), var_export($value, true));
        }

        $code .= 'echo \OWA\Core\CoreAPI::generateInstanceSpecificHash();';

        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    }

    /** OWA_DB_USER and OWA_DB_PASSWORD are not part of it; OWA_SECRET is all of it. */
    public function testTheDatabaseCredentialsDoNotChangeTheHash(): void
    {
        $expected = \OWA\Core\CoreAPI::privateFilesHash('the-secret');

        $this->assertSame($expected, self::hashUnder(['OWA_SECRET' => 'the-secret']));
        $this->assertSame($expected, self::hashUnder(['OWA_SECRET' => 'the-secret', 'OWA_DB_USER' => 'one', 'OWA_DB_PASSWORD' => 'pw1']));
        $this->assertSame($expected, self::hashUnder(['OWA_SECRET' => 'the-secret', 'OWA_DB_USER' => 'two', 'OWA_DB_PASSWORD' => 'pw2']));
        $this->assertSame(\OWA\Core\CoreAPI::privateFilesHash(''), self::hashUnder(['OWA_DB_USER' => 'one', 'OWA_DB_PASSWORD' => 'pw1']),
            'no OWA_SECRET: the key is empty, whatever the credentials');
    }

    public function testHashOfReadsAnyInstallsHash(): void
    {
        $other = str_repeat('a', 32);

        $this->assertSame($other, PrivateFiles::hashOf('errors_' . $other . '.txt', 'errors', '.txt'));
        $this->assertSame(\OWA\Core\CoreAPI::generateInstanceSpecificHash(), PrivateFiles::hashOf(PrivateFiles::name('errors', '.txt'), 'errors', '.txt'));
        $this->assertNull(PrivateFiles::hashOf('errors_' . $other . '.txt', 'events', '.txt'));
        $this->assertNull(PrivateFiles::hashOf('errors_short.txt', 'errors', '.txt'));
        $this->assertNull(PrivateFiles::hashOf('errors_' . $other . '.txt.bak', 'errors', '.txt'));
    }

    public function testSystemHealthNamesAnErrorLogUnderAnotherHash(): void
    {
        file_put_contents($this->dir . PrivateFiles::name('errors', '.txt'), "now\n");
        $this->assertNull(\OWA\Module\Base\Classes\SystemHealth::oldErrorLogs($this->dir), 'this install\'s own log is no finding');

        $old = $this->dir . 'errors_' . str_repeat('b', 32) . '.txt';
        file_put_contents($old, "before\n");
        mkdir($this->dir . 'errors_' . str_repeat('c', 32) . '.txt');

        $finding = \OWA\Module\Base\Classes\SystemHealth::oldErrorLogs($this->dir);
        $this->assertSame('yellow', $finding['level']);
        $this->assertSame('Earlier error logs', $finding['label']);
        $this->assertSame('Errors logged before OWA_SECRET was set or changed are in ' . $old . '. Delete each once read.',
            $finding['detail'], 'the file, not the directory of the same shape');
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
