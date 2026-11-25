<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * cmd=install leaves the data directory as a split setup needs it: writable
 * by its owner only, so the overrides in its conf/ are run (Core\Overrides),
 * but for logs/, which the web server writes: group-writable and setgid.
 */
final class InstallSecureDataDirTest extends TestCase
{
    /** @var string */
    private $data = '';

    /** @var mixed */
    private $saved;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid')) {
            $this->markTestSkipped('no file modes here');
        }

        $this->data  = sys_get_temp_dir() . '/owa-install-data-' . bin2hex(random_bytes(4));
        $this->saved = \OWA\Core\CoreAPI::getSetting('base', 'data_dir');

        $old = umask(0);
        mkdir($this->data . '/logs', 0777, true);
        mkdir($this->data . '/conf', 0775);
        chmod($this->data, 0777);
        chmod($this->data . '/logs', 0755);
        umask($old);

        \OWA\Core\CoreAPI::setSetting('base', 'data_dir', $this->data);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'data_dir', $this->saved);

        if ($this->data !== '') {
            @rmdir($this->data . '/logs');
            @rmdir($this->data . '/conf');
            @rmdir($this->data);
        }
    }

    private function secure(string $group = ''): array
    {
        $cli = (new ReflectionClass(\OWA\Module\Base\Controller\InstallCli::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($cli, 'secureDataDir'))->invoke($cli, $group);
    }

    public function testOnlyLogsStaysWritableByTheWebServersGroup(): void
    {
        $mine = posix_getgrgid(posix_getegid())['name'];
        $said = $this->secure($mine);

        $this->assertSame(0, fileperms($this->data) & 0022, 'the data directory: its owner only');
        $this->assertSame(0, fileperms($this->data . '/conf') & 0022, 'conf/: its owner only');
        $this->assertSame(02070, fileperms($this->data . '/logs') & 02070, 'logs/: group-writable, setgid');
        $this->assertSame(0, fileperms($this->data . '/logs') & 0002, 'logs/: not world-writable');
        $this->assertContains($this->data . ' is now writable by its owner only.', $said);

        $this->assertSame([], $this->secure($mine), 'nothing more to change');
    }
}
