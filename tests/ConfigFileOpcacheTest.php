<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Writing the config file evicts OPcache's compiled copy of any earlier file at
 * that path. Without it, a worker that had included the earlier file kept
 * running it until OPcache next checked the timestamp, so a re-install's next
 * request connected to the old (installed) database and redirected to login.
 */
final class ConfigFileOpcacheTest extends TestCase
{
    private $file;
    private $was;

    protected function setUp(): void
    {
        if (!function_exists('opcache_is_script_cached') || !ini_get('opcache.enable_cli')) {
            $this->markTestSkipped('needs OPcache enabled for the CLI');
        }

        $this->file = sys_get_temp_dir() . '/owa-config-opcache-' . bin2hex(random_bytes(4)) . '.php';
        $this->was  = \OWA\Core\CoreAPI::getSetting('base', 'config_file');
    }

    protected function tearDown(): void
    {
        if ($this->file) {
            \OWA\Core\CoreAPI::setSetting('base', 'config_file', $this->was);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->file, true);
            }

            @unlink($this->file);
        }
    }

    public function testWritingTheConfigEvictsTheCompiledEarlierFile(): void
    {
        // An earlier config, compiled. Its mtime is put in the past because
        // OPcache will not cache a file modified in the last
        // opcache.file_update_protection seconds.
        file_put_contents($this->file, "<?php // the earlier install's config\n");
        touch($this->file, time() - 60);
        clearstatcache();
        opcache_compile_file($this->file);
        $this->assertTrue(opcache_is_script_cached($this->file), 'precondition: the earlier file is cached');

        unlink($this->file);

        \OWA\Core\CoreAPI::setSetting('base', 'config_file', $this->file);
        $this->assertTrue(\OWA\Core\CoreAPI::configSingleton()->createConfigFile([
            'db_type'     => 'mysql',
            'db_name'     => 'owa_org_123456789',
            'db_user'     => 'alice',
            'db_password' => 'secret',
            'db_host'     => '127.0.0.1',
            'db_port'     => '3306',
            'public_url'  => 'https://example.test/',
        ]));

        $this->assertFileExists($this->file);
        $this->assertFalse(opcache_is_script_cached($this->file), 'the next request must compile the new file');
        $this->assertStringContainsString("'owa_org_123456789'", (string) file_get_contents($this->file));
    }
}
