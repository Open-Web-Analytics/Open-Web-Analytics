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

    /** cmd=reset-secrets never changes OWA_SECRET, and its usage says so. */
    public function testRotationLeavesOwaSecretAlone(): void
    {
        $secret = "define('OWA_SECRET', 'kept-" . str_repeat('s', 40) . "');\n";
        $path = $this->config(self::OTHER_LINES . $this->fourDefines() . $secret);

        $this->assertNull(ConfigSecrets::rotate($path)['error']);

        $after = (string) file_get_contents($path);
        $this->assertStringNotContainsString("'old-", $after, 'the four rotated');
        $this->assertStringEndsWith($secret, $after, 'OWA_SECRET as it was');
        $this->assertNotContains('OWA_SECRET', ConfigSecrets::NAMES);
        $this->assertStringContainsString('OWA_SECRET is never changed',
            \OWA\Module\Base\Controller\ResetSecretsCli::usage()['description']);
    }

    /** A generator that answers this value. */
    private static function value(string $value): callable
    {
        return static fn () => $value;
    }

    /** OWA_SECRET's define()s in a file, as locate() finds them. */
    private static function secrets(string $path): array
    {
        return ConfigSecrets::locate((string) file_get_contents($path), ['OWA_SECRET'])['OWA_SECRET'];
    }

    public function testAMissingOwaSecretIsAddedAtTheEnd(): void
    {
        $source = self::OTHER_LINES . $this->fourDefines();
        $path = $this->config($source, 0640);

        $result = ConfigSecrets::addInstanceSecret($path, self::value('a-new-secret'));

        $this->assertNull($result['error']);
        $this->assertSame('added', $result['written']);
        $this->assertSame($source . ConfigSecrets::instanceSecretLines('a-new-secret'), file_get_contents($path));
        $this->assertSame("\n" . ConfigSecrets::INSTANCE_SECRET_COMMENT . "\ndefine('OWA_SECRET', 'a-new-secret');\n",
            ConfigSecrets::instanceSecretLines('a-new-secret'));
        clearstatcache();
        $this->assertSame('0640', sprintf('%04o', fileperms($path) & 07777), 'the mode kept');
        $this->assertSame(['config.php'], $this->files(), 'no temp file left');
    }

    /** Code after a closing ?> is output, not code: the define() goes above it. */
    public function testItIsAddedAboveAClosingTag(): void
    {
        $code = self::OTHER_LINES . $this->fourDefines();
        $path = $this->config($code . "?>\n");

        $this->assertNull(ConfigSecrets::addInstanceSecret($path, self::value('above-the-tag'))['error']);
        $this->assertSame($code . ConfigSecrets::instanceSecretLines('above-the-tag') . "?>\n", file_get_contents($path));

        $run = sprintf('include %s; echo OWA_SECRET;', var_export($path, true));
        $this->assertSame('above-the-tag', shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($run)));
    }

    public function testTheDefaultValueIsLongEnoughAndNotThePlaceholder(): void
    {
        $path = $this->config(self::OTHER_LINES);

        $this->assertNull(ConfigSecrets::addInstanceSecret($path)['error']);

        $found = self::secrets($path);
        $this->assertCount(1, $found);
        $value = eval('return ' . $found[0]['literal'] . ';');
        $this->assertSame(ConfigSecrets::LENGTH, strlen($value));
        $this->assertNotContains($value, \OWA\Core\Requirements::SECRET_PLACEHOLDERS);
    }

    /** owa-config-dist.php's line, as a hand-written file copies it. */
    public function testThePlaceholderIsReplaced(): void
    {
        $path = $this->config(self::OTHER_LINES . "define('OWA_SECRET', 'yoursecretgoeshere'); // from the template\n");

        $result = ConfigSecrets::addInstanceSecret($path, self::value('replacing-it'));

        $this->assertNull($result['error']);
        $this->assertSame('replaced', $result['written']);
        $this->assertSame(self::OTHER_LINES . "define('OWA_SECRET', 'replacing-it'); // from the template\n", file_get_contents($path));
    }

    public function testAValueOfItsOwnIsLeftAlone(): void
    {
        $source = self::OTHER_LINES . "define( \"OWA_SECRET\", 'one-of-its-own' );\n";
        $path = $this->config($source);
        $mtime = filemtime($path);

        $result = ConfigSecrets::addInstanceSecret($path, self::value('never-written'));

        $this->assertNull($result['error']);
        $this->assertNull($result['written']);
        $this->assertSame($source, file_get_contents($path));
        $this->assertSame($mtime, filemtime($path));
    }

    /** One in a comment is not one: it is added. */
    public function testACommentedOutOneIsNotCounted(): void
    {
        $source = self::OTHER_LINES . "// define('OWA_SECRET', 'commented');\n";
        $path = $this->config($source);

        $this->assertSame('added', ConfigSecrets::addInstanceSecret($path, self::value('real-one'))['written']);
        $this->assertCount(1, self::secrets($path));
    }

    public function testTwoDefinesOrAnUnquotedValueChangeNothing(): void
    {
        $twice = self::OTHER_LINES . "define('OWA_SECRET', 'a');\ndefine('OWA_SECRET', 'b');\n";
        $path = $this->config($twice);
        $this->assertStringContainsString('Defined more than once: OWA_SECRET (2 times).',
            (string) ConfigSecrets::addInstanceSecret($path)['error']);
        $this->assertSame($twice, file_get_contents($path));

        $unquoted = self::OTHER_LINES . "define('OWA_SECRET', getenv('S'));\n";
        file_put_contents($path, $unquoted);
        $this->assertStringContainsString('Value is not a quoted string: OWA_SECRET.',
            (string) ConfigSecrets::addInstanceSecret($path)['error']);
        $this->assertSame($unquoted, file_get_contents($path));
    }

    /** A file that cannot be replaced: the line to add, with no value in it. */
    public function testAnUnwritableFileFailsWithTheLineToAdd(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a read-only directory.');
        }

        $source = self::OTHER_LINES;
        $path = $this->config($source);
        chmod($this->dir, 0500);

        $result = ConfigSecrets::addInstanceSecret($path, self::value('must-not-be-shown'));

        chmod($this->dir, 0700);
        $this->assertNull($result['written']);
        $this->assertStringContainsString('Could not create', (string) $result['error']);
        $this->assertStringContainsString("define('OWA_SECRET', 'RANDOM');", (string) $result['error']);
        $this->assertStringContainsString(\OWA\Core\Requirements::INSTANCE_SECRET_COMMAND, (string) $result['error']);
        $this->assertStringNotContainsString('must-not-be-shown', (string) $result['error']);
        $this->assertSame($source, file_get_contents($path));
        $this->assertSame(['config.php'], $this->files());
    }

    /** Only the lines it added, by their hash: not lines with another value, not a define() of the same value written otherwise. */
    public function testRemovalTakesOutOnlyTheLinesAdded(): void
    {
        $source = self::OTHER_LINES . $this->fourDefines() . "?>\n";
        $path = $this->config($source);
        $this->assertNull(ConfigSecrets::addInstanceSecret($path, self::value('added-here'))['error']);
        $added = (string) file_get_contents($path);

        $other = ConfigSecrets::removeInstanceSecret($path, hash('sha256', ConfigSecrets::instanceSecretLines('another-value')));
        $this->assertNull($other['error']);
        $this->assertFalse($other['removed']);
        $this->assertSame($added, file_get_contents($path));

        $ours = ConfigSecrets::removeInstanceSecret($path, hash('sha256', ConfigSecrets::instanceSecretLines('added-here')));
        $this->assertNull($ours['error']);
        $this->assertTrue($ours['removed']);
        $this->assertSame($source, file_get_contents($path), 'the file as it was');

        $own = self::OTHER_LINES . "define('OWA_SECRET', 'added-here');\n";
        file_put_contents($path, $own);
        $this->assertFalse(ConfigSecrets::removeInstanceSecret($path, hash('sha256', ConfigSecrets::instanceSecretLines('added-here')))['removed']);
        $this->assertSame($own, file_get_contents($path));
    }

    /** core-install's step (InstallManager::writeInstanceSecret()): what it did, and never the value. */
    public function testTheCliInstallersStepWritesItAndSaysSo(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
        $this->config_file = \OWA\Core\CoreAPI::getSetting('base', 'config_file');
        $path = $this->config(self::OTHER_LINES . "define('OWA_SECRET', 'yoursecretgoeshere');\n");
        \OWA\Core\CoreAPI::setSetting('base', 'config_file', $path);

        $im = new \OWA\Module\Base\Classes\InstallManager();
        $first = $im->writeInstanceSecret();

        $this->assertSame('', $first['problem']);
        $this->assertSame(['Replaced the example OWA_SECRET in ' . realpath($path) . ' with a generated one.'], $first['lines']);
        $value = eval('return ' . self::secrets($path)[0]['literal'] . ';');
        $this->assertSame(ConfigSecrets::LENGTH, strlen($value));
        $this->assertStringNotContainsString($value, implode(' ', $first['lines']));

        $this->assertSame(['problem' => '', 'lines' => []], $im->writeInstanceSecret(), 'a second run finds it and does nothing');

        file_put_contents($path, self::OTHER_LINES);
        $this->assertSame(['Added a generated OWA_SECRET to ' . realpath($path) . '.'], $im->writeInstanceSecret()['lines']);
    }

    /** The command: 0 and a rotated file, 1 and an untouched one. */
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
