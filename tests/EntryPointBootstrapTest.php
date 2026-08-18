<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every entry point must bootstrap from ITS OWN directory, never from whatever
 * directory it happens to be invoked from.
 *
 * WHY IT MATTERS
 * The entry points open with require_once('owa_env.php'), and PHP resolves a
 * relative include against include_path FIRST -- which begins with '.', the
 * current working directory. On a machine with more than one OWA installation
 * that is a live hazard: running
 *
 *     php /var/www/installA/cli.php cmd=...
 *
 * while sitting in installB's directory loads installB's owa_env.php, so
 * OWA_PATH points at installB and the command silently operates on the WRONG
 * installation -- wrong database, wrong config, no error. The failure is
 * invisible because the command runs perfectly; it just runs somewhere else.
 *
 * Anchoring the include to __DIR__ removes the ambiguity: an entry point always
 * boots the installation it belongs to.
 */
final class EntryPointBootstrapTest extends TestCase
{
    /** Every PHP entry point in the OWA root that bootstraps the environment. */
    private const ENTRY_POINTS = [
        'cli.php', 'index.php', 'install.php', 'log.php', 'owa.php', 'queue.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * The static half: no entry point may reach for owa_env.php by a bare
     * relative name, whether or not a decoy is around to prove it today.
     *
     * @dataProvider entryPoints
     */
    public function testTheBootstrapIncludeIsAnchoredToTheScriptDirectory(string $file): void
    {
        $path = $this->root() . '/' . $file;

        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/(require|include)(_once)?\s*\(\s*__DIR__\s*\.\s*[\'"]\/owa_env\.php[\'"]\s*\)/',
            $src,
            $file . ' must include owa_env.php relative to __DIR__.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(require|include)(_once)?\s*\(\s*[\'"]owa_env\.php[\'"]\s*\)/',
            $src,
            $file . ' includes owa_env.php by a bare relative name, so the current working '
            . 'directory decides which installation boots.'
        );
    }

    /** @return array<int, array<int, string>> */
    public static function entryPoints(): array
    {
        return array_map(static fn ($f) => [$f], self::ENTRY_POINTS);
    }

    /**
     * The behavioural half, and the one that actually proves it: stand a DECOY
     * owa_env.php in the working directory and confirm cli.php ignores it.
     *
     * Before the fix this test's decoy wins, because '.' precedes the script's
     * own directory in include_path. A source assertion alone could be satisfied
     * by a change that still resolves the wrong file.
     */
    public function testAnEntryPointIgnoresAnOwaEnvInTheWorkingDirectory(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; the entry point cannot complete its boot.');
        }

        $dir = sys_get_temp_dir() . '/owa_decoy_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $this->assertTrue(mkdir($dir, 0700, true), 'could not create the decoy directory');

        try {
            // If this file is ever loaded, it announces itself and stops the
            // process before OWA can mask the mistake.
            file_put_contents($dir . '/owa_env.php', "<?php\nfwrite(STDOUT, 'DECOY_ENV_WAS_LOADED');\nexit(9);\n");

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $proc = proc_open(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root() . '/cli.php'),
                $descriptors,
                $pipes,
                $dir                       // <-- the working directory under test
            );

            $this->assertIsResource($proc, 'could not spawn the entry point');

            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($proc);

            $this->assertStringNotContainsString('DECOY_ENV_WAS_LOADED', $stdout,
                'cli.php loaded owa_env.php from the working directory instead of its own -- '
                . 'on a multi-install machine that boots the wrong installation.');

            $this->assertNotSame(9, $status,
                'cli.php exited through the decoy, so the working directory decided which '
                . 'environment was loaded.');

        } finally {
            @unlink($dir . '/owa_env.php');
            @rmdir($dir);
        }
    }
}
