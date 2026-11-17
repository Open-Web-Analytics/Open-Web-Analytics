<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Settings::createConfigFile() answers false when the file is already there,
 * and leaves it as it was.
 *
 * It answered true, as it does after writing one.
 */
final class CreateConfigFileAnswerTest extends TestCase
{
    use CapturesLog;

    private $file;
    private $was;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/owa-config-exists-' . bin2hex(random_bytes(4)) . '.php';
        $this->was  = \OWA\Core\CoreAPI::getSetting('base', 'config_file');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'config_file', $this->was);
        @unlink($this->file);
    }

    public function testAnExistingFileIsLeftAndTheAnswerIsFalse(): void
    {
        $existing = "<?php // an install's own config\n";
        file_put_contents($this->file, $existing);

        \OWA\Core\CoreAPI::setSetting('base', 'config_file', $this->file);

        $answer = null;
        $log = $this->logDuring(function () use (&$answer) {
            $answer = \OWA\Core\CoreAPI::configSingleton()->createConfigFile([
                'db_type'     => 'mysql',
                'db_name'     => 'owa_probe',
                'db_user'     => 'probe',
                'db_password' => 'secret',
                'db_host'     => '127.0.0.1',
                'db_port'     => '3306',
                'public_url'  => 'https://example.test/',
            ]);
        });

        $this->assertFalse($answer);
        $this->assertSame($existing, file_get_contents($this->file));
        $this->assertStringContainsString('Your config file already exists.', $log);
    }
}
