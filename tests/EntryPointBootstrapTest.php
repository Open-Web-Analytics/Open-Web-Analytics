<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every entry point must bootstrap from ITS OWN directory, never from whatever
 * directory it happens to be invoked from.
 *
 * WHY IT MATTERS
 * An entry point that opened with require_once('owa_env.php') would have PHP
 * resolve that relative include against include_path FIRST -- which begins with '.', the
 * current working directory. On a machine with more than one OWA installation
 * that is a live hazard: running
 *
 *     php /var/www/installA/cli.php cmd=...
 *
 * while sitting in installB's directory loads installB's owa_env.php and its
 * autoloader, so installB's classes -- and Core\Env::root() -- answer, and the
 * command silently operates on the WRONG installation -- wrong database, wrong config, no error. The failure is
 * invisible because the command runs perfectly; it just runs somewhere else.
 *
 * Anchoring the include to __DIR__ removes the ambiguity: an entry point always
 * boots the installation it belongs to.
 */
final class EntryPointBootstrapTest extends TestCase
{
    /** Every PHP entry point that bootstraps the environment. */
    private const ENTRY_POINTS = [
        'cli.php', 'index.php', 'install.php', 'log.php', 'owa.php', 'api/index.php', 'Core/Caller.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return array<int, array<int, string>> */
    public static function entryPoints(): array
    {
        return array_map(static fn ($f) => [$f], self::ENTRY_POINTS);
    }

    /**
     * Stand a DECOY owa_env.php and owa.php in the working directory, run the
     * entry point there, and list the files it loaded: its own installation's
     * owa_env.php is among them and the decoys are not.
     *
     * Resolved through include_path a decoy would win, because '.' precedes
     * the script's own directory there. The database connect is disabled, so
     * the run needs no database and writes nothing.
     *
     * @dataProvider entryPoints
     */
    public function testAnEntryPointIgnoresAnOwaEnvInTheWorkingDirectory(string $file): void
    {
        $dir = sys_get_temp_dir() . '/owa_decoy_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $this->assertTrue(mkdir($dir, 0700, true), 'could not create the decoy directory');

        $loaded  = $dir . '/loaded.txt';
        $prepend = $dir . '/prepend.php';

        try {
            // If either is ever loaded, it announces itself and stops the
            // process before OWA can mask the mistake.
            foreach (['owa_env.php', 'owa.php'] as $decoy) {
                file_put_contents($dir . '/' . $decoy, "<?php\nfwrite(STDOUT, 'DECOY_ENV_WAS_LOADED');\nexit(9);\n");
            }

            // Shutdown functions run after exit() too, so the list is written however the script ends.
            file_put_contents($prepend, "<?php\nregister_shutdown_function(static function () {\n"
                . '    file_put_contents(' . var_export($loaded, true) . ", implode(\"\\n\", get_included_files()));\n});\n");

            $proc = proc_open(
                escapeshellarg(PHP_BINARY)
                    . ' -d ' . escapeshellarg('auto_prepend_file=' . $prepend)
                    . ' -d ' . escapeshellarg('disable_functions=mysqli_real_connect,mysqli_connect')
                    . ' ' . escapeshellarg($this->root() . '/' . $file),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $dir                       // <-- the working directory under test
            );

            $this->assertIsResource($proc, 'could not spawn the entry point');

            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($proc);

            $this->assertStringNotContainsString('DECOY_ENV_WAS_LOADED', $stdout,
                $file . ' loaded a bootstrap file from the working directory instead of its own -- '
                . 'on a multi-install machine that boots the wrong installation.');

            $this->assertNotSame(9, $status,
                $file . ' exited through a decoy, so the working directory decided which '
                . 'environment was loaded.');

            $files = file_exists($loaded) ? explode("\n", (string) file_get_contents($loaded)) : [];

            $this->assertContains(realpath($this->root() . '/owa_env.php'), $files,
                $file . ' did not load its own installation\'s owa_env.php');

            $this->assertSame([], array_values(array_filter($files,
                static fn ($f) => strpos($f, $dir . '/owa') === 0)), $file . ' loaded a decoy');

        } finally {
            foreach (['owa_env.php', 'owa.php', 'loaded.txt', 'prepend.php'] as $f) {
                @unlink($dir . '/' . $f);
            }
            @rmdir($dir);
        }
    }
}
