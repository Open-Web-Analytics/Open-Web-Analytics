<?php

use PHPUnit\Framework\TestCase;

/**
 * The error log must be created group-writable.
 *
 * Two accounts write to the same file: the web server user, and whoever runs
 * the CLI. Without an explicit mode the file inherits the umask of whichever
 * process creates it first -- 0664 from the web server (umask 002), but 0644
 * from a shell (umask 022). In the second case the web server cannot append,
 * and a log write that cannot open its file raises, so a notice becomes a
 * fatal.
 *
 * That is not a one-off: the path embeds an instance-specific hash derived from
 * the credentials, so a new file appears -- with a fresh race over who creates
 * it -- on every rotation.
 *
 * This asserts the mode of a file the logger actually creates, rather than
 * looking for the argument in the source, so it holds however the code is
 * arranged.
 */
final class ErrorLogFilePermissionTest extends TestCase
{
    /** @var string */
    private $path;

    /** @var string|null */
    private $previous;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping log permission test.');
        }

        // isSafeLogPath() constrains where the logger will write, so stay inside
        // the data directory the real setting uses.
        $this->path = \OWA\Core\Env::dataDir() . 'logs/errors_test_' . bin2hex(random_bytes(4)) . '.txt';

        $this->previous = \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');
        \OWA\Core\CoreAPI::setSetting('base', 'error_log_file', $this->path);
    }

    protected function tearDown(): void
    {
        if ($this->path && file_exists($this->path)) {
            unlink($this->path);
        }

        if ($this->previous !== null) {
            \OWA\Core\CoreAPI::setSetting('base', 'error_log_file', $this->previous);
        }
    }

    /**
     * A shell umask of 022 is what produced the unusable 0644 file, so set it
     * explicitly: without an explicit permission the file would come out 0644
     * and the assertion would fail.
     *
     * @covers-setting base.error_log_file
     */
    public function testLogFileIsCreatedGroupWritable()
    {
        $old_umask = umask(022);

        try {
            // 'production' reaches make_file_logger() without installing the
            // global exception handler that 'development' adds.
            $e = new \OWA\Module\Base\Classes\Error();
            $e->setHandler('production');
            $e->notice('permission probe');

            clearstatcache(true, $this->path);

            $this->assertFileExists($this->path, 'the logger should have created its file');

            $mode = fileperms($this->path) & 0777;

            $this->assertSame(
                0664,
                $mode,
                sprintf('expected 0664, got %o -- the group could not append', $mode)
            );

        } finally {
            umask($old_umask);
        }
    }

    /**
     * The web server's user writes the data directory: a link it puts at the
     * log's name, where the CLI's user writes too, is never written through.
     */
    public function testALinkAtTheLogsNameIsNotWrittenThrough()
    {
        $victim = sys_get_temp_dir() . '/owa-log-victim-' . bin2hex(random_bytes(4));
        file_put_contents($victim, 'the cron user\'s');
        symlink($victim, $this->path);

        try {
            $e = new \OWA\Module\Base\Classes\Error();
            $e->setHandler('production');
            $e->notice('through a link');

            $this->assertSame('the cron user\'s', file_get_contents($victim));
        } finally {
            unlink($this->path);
            unlink($victim);
        }
    }
}
