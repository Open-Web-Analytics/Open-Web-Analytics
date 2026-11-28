<?php

use OWA\Core\Controller\Cli;
use OWA\Module\Base\Classes\ConfigSecrets;
use PHPUnit\Framework\TestCase;

/**
 * cmd=reset-secrets (Controller\ResetSecretsCli, Classes\ConfigSecrets) on
 * config files in a scratch directory. No database: the command's action()
 * only rewrites the file base.config_file names.
 */
final class ConfigSecretsTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var mixed base.config_file before the test */
    private $config_file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-secrets-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        ConfigSecrets::$created = null;
        Cli::$exit_code = 0;
    }

    protected function tearDown(): void
    {
        ConfigSecrets::$created = null;
        Cli::$exit_code = 0;

        if ($this->config_file !== null) {
            \OWA\Core\CoreAPI::setSetting('base', 'config_file', $this->config_file);
        }

        @chmod($this->dir, 0700);

        foreach (scandir($this->dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                unlink($this->dir . '/' . $f);
            }
        }

        rmdir($this->dir);
    }

    private function config(string $source, int $mode = 0640): string
    {
        $path = $this->dir . '/config.php';
        file_put_contents($path, $source);
        chmod($path, $mode);

        return $path;
    }

    /** @return string[] what is in the scratch directory */
    private function files(): array
    {
        return array_values(array_diff(scandir($this->dir), ['.', '..']));
    }

    /** A generator of known values, with every character the escaping must handle. */
    private function values(): callable
    {
        $n = 0;

        return static function () use (&$n) {
            $n++;
            return "new{$n}\$x'\"\\{\$y}" . str_repeat('a', 50);
        };
    }

    private const OTHER_LINES = "<?php\n\n"
        . "define('OWA_DB_TYPE', 'mysql'); // the database\n"
        . "  define( 'OWA_PUBLIC_URL' , \"https://example.test/owa/\" );\n"
        . "\$x = 'OWA_AUTH_KEY';\n";

    public function testSpacedAndDoubleQuotedDefinesRotateAndNothingElseChanges(): void
    {
        $source = self::OTHER_LINES
            . "    define( \"OWA_NONCE_KEY\" ,   \"old-1\"   );   // keep me\n"
            . "define ('OWA_NONCE_SALT','old-2');\n"
            . "\tdefine(  'OWA_AUTH_KEY', 'old-3' ) ;\n"
            . "\\define(\"OWA_AUTH_SALT\", 'old-4', false);\n"
            . "define('OWA_ERROR_HANDLER', 'production');\n";
        $path = $this->config($source);

        $result = ConfigSecrets::rotate($path, $this->values());

        $this->assertNull($result['error']);
        $this->assertSame([], $result['warnings']);

        // Byte for byte: only the four value literals differ, each keeping its quote.
        $expected = self::OTHER_LINES
            . "    define( \"OWA_NONCE_KEY\" ,   \"new1\\\$x'\\\"\\\\{\\\$y}" . str_repeat('a', 50) . "\"   );   // keep me\n"
            . "define ('OWA_NONCE_SALT','new2\$x\\'\"\\\\{\$y}" . str_repeat('a', 50) . "');\n"
            . "\tdefine(  'OWA_AUTH_KEY', 'new3\$x\\'\"\\\\{\$y}" . str_repeat('a', 50) . "' ) ;\n"
            . "\\define(\"OWA_AUTH_SALT\", 'new4\$x\\'\"\\\\{\$y}" . str_repeat('a', 50) . "', false);\n"
            . "define('OWA_ERROR_HANDLER', 'production');\n";
        $this->assertSame($expected, file_get_contents($path));

        // And PHP reads each value back as the one generated.
        $values = $this->values();
        foreach (ConfigSecrets::locate(file_get_contents($path)) as $name => $at) {
            $this->assertCount(1, $at, $name);
            $this->assertSame($values(), eval('return ' . $at[0]['literal'] . ';'), $name);
        }

        $this->assertSame(['config.php'], $this->files(), 'no temp file left');
    }

    public function testTheDefaultValuesAreNewAndLongEnough(): void
    {
        $path = $this->config(self::OTHER_LINES . $this->fourDefines());

        $this->assertNull(ConfigSecrets::rotate($path)['error']);

        $seen = [];
        foreach (ConfigSecrets::locate(file_get_contents($path)) as $name => $at) {
            $value = eval('return ' . $at[0]['literal'] . ';');
            $this->assertSame(ConfigSecrets::LENGTH, strlen($value), $name);
            $this->assertStringNotContainsString('old', $value, $name);
            $seen[] = $value;
        }

        $this->assertCount(4, array_unique($seen));
    }

    private function fourDefines(string $skip = ''): string
    {
        $out = '';
        foreach (ConfigSecrets::NAMES as $i => $name) {
            if ($name !== $skip) {
                $out .= "define('$name', 'old-$i');\n";
            }
        }

        return $out;
    }

    public function testAMissingSecretRefusesAndChangesNothing(): void
    {
        $source = self::OTHER_LINES . $this->fourDefines('OWA_AUTH_SALT');
        $path = $this->config($source);
        $mtime = filemtime($path);

        $result = ConfigSecrets::rotate($path);

        $this->assertStringContainsString('Not defined: OWA_AUTH_SALT.', (string) $result['error']);
        $this->assertSame($source, file_get_contents($path));
        $this->assertSame($mtime, filemtime($path));
        $this->assertSame(['config.php'], $this->files());
    }

    public function testADuplicateRefusesAndNamesIt(): void
    {
        $source = self::OTHER_LINES . $this->fourDefines() . "define( 'OWA_AUTH_KEY', 'again' );\n";
        $path = $this->config($source);

        $result = ConfigSecrets::rotate($path);

        $this->assertStringContainsString('Defined more than once: OWA_AUTH_KEY (2 times).', (string) $result['error']);
        $this->assertSame($source, file_get_contents($path));
    }

    public function testAValueThatIsNotAQuotedStringRefuses(): void
    {
        $source = self::OTHER_LINES . $this->fourDefines('OWA_NONCE_KEY') . "define('OWA_NONCE_KEY', getenv('K'));\n";
        $path = $this->config($source);

        $result = ConfigSecrets::rotate($path);

        $this->assertStringContainsString('Value is not a quoted string: OWA_NONCE_KEY.', (string) $result['error']);
        $this->assertSame($source, file_get_contents($path));
    }

    public function testCommentedOutDefinesAreIgnored(): void
    {
        $comments = "// define('OWA_AUTH_KEY', 'line-comment');\n"
            . "#define( \"OWA_AUTH_KEY\", 'hash-comment' );\n"
            . "/*\ndefine('OWA_NONCE_KEY', 'block-comment');\n*/\n"
            . "/** define('OWA_AUTH_SALT', 'doc-comment'); */\n";
        $source = self::OTHER_LINES . $comments . $this->fourDefines();
        $path = $this->config($source);

        $this->assertSame([], ConfigSecrets::problems(ConfigSecrets::locate($source)));
        $this->assertNull(ConfigSecrets::rotate($path)['error']);

        $after = file_get_contents($path);
        $this->assertStringStartsWith(self::OTHER_LINES . $comments, $after, 'comments untouched');
        $this->assertStringNotContainsString("'old-", $after, 'the real defines rotated');
    }

    public function testTheModeIsKept(): void
    {
        $path = $this->config(self::OTHER_LINES . $this->fourDefines(), 0640);

        $this->assertNull(ConfigSecrets::rotate($path)['error']);

        clearstatcache();
        $this->assertSame('0640', sprintf('%04o', fileperms($path) & 07777));
    }

    public function testTheTempFileIsOwnerOnlyBeforeAnythingIsWritten(): void
    {
        $path = $this->config(self::OTHER_LINES . $this->fourDefines(), 0644);
        $seen = [];
        ConfigSecrets::$created = static function (string $temp) use (&$seen) {
            clearstatcache();
            $seen[] = [dirname($temp), sprintf('%04o', fileperms($temp) & 07777), filesize($temp)];
        };

        $umask = umask(0);
        try {
            $this->assertNull(ConfigSecrets::rotate($path)['error']);
        } finally {
            umask($umask);
        }

        $this->assertSame([[$this->dir, '0600', 0]], $seen, 'created in the same directory, 0600 and empty');
    }

    public function testCreateTempIsOwnerOnlyUnderAnOpenUmask(): void
    {
        $umask = umask(0);
        try {
            $handle = ConfigSecrets::createTemp($this->dir . '/t.tmp');
        } finally {
            umask($umask);
        }

        $this->assertIsResource($handle);
        fclose($handle);
        clearstatcache();
        $this->assertSame('0600', sprintf('%04o', fileperms($this->dir . '/t.tmp') & 07777));
        $this->assertFalse(@ConfigSecrets::createTemp($this->dir . '/t.tmp'), 'never reuses an existing file');
    }

    public function testAFailedWriteLeavesTheOriginalAndNoTempFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a read-only directory.');
        }

        $source = self::OTHER_LINES . $this->fourDefines();
        $path = $this->config($source);
        chmod($this->dir, 0500);

        $result = ConfigSecrets::rotate($path);

        chmod($this->dir, 0700);
        $this->assertStringContainsString('Could not create', (string) $result['error']);
        $this->assertSame($source, file_get_contents($path));
        $this->assertSame(['config.php'], $this->files());
    }

    public function testAMissingFileRefuses(): void
    {
        $result = ConfigSecrets::rotate($this->dir . '/none.php');

        $this->assertSame($this->dir . '/none.php does not exist. Nothing was changed.', $result['error']);
    }

    /**
     * The command: 0 and a rotated file, 1 and an untouched one.
     *
     * @covers-setting base.config_file
     */
    public function testTheCommandsExitCodes(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Open Web Analytics CLI test';
        $_SERVER['REMOTE_ADDR']     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        require_once dirname(__DIR__) . '/owa.php';

        if (!isset($GLOBALS['owa_test_cli_instance'])) {
            $GLOBALS['owa_test_cli_instance'] = new owa(['request_type' => 'cli']);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
        $this->config_file = \OWA\Core\CoreAPI::getSetting('base', 'config_file');

        $good = self::OTHER_LINES . $this->fourDefines();
        $path = $this->config($good);
        \OWA\Core\CoreAPI::setSetting('base', 'config_file', $path);
        // Never the install's own file.
        $this->assertSame($path, \OWA\Core\CoreAPI::getSetting('base', 'config_file'));

        ob_start();
        (new \OWA\Module\Base\Controller\ResetSecretsCli([]))->action();
        ob_end_clean();

        $this->assertSame(0, Cli::$exit_code);
        $this->assertNotSame($good, file_get_contents($path));

        $bad = self::OTHER_LINES . $this->fourDefines('OWA_NONCE_SALT');
        file_put_contents($path, $bad);

        ob_start();
        (new \OWA\Module\Base\Controller\ResetSecretsCli([]))->action();
        ob_end_clean();

        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame($bad, file_get_contents($path));
    }
}
