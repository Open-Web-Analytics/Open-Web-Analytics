<?php

use OWA\Core\Env;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\TrackerBundle;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * updater.php `keeps`, the paths an install never deletes, against what OWA
 * writes as it runs. An install deletes, in each directory a release ships,
 * the files the release does not ship: a file OWA writes there must be kept,
 * or the next install deletes it.
 *
 * WRITERS is every file of OWA's code that writes a file, and where it
 * writes. A file that starts writing fails the test until it is added, and
 * what it writes inside the OWA directory is kept.
 */
final class UpdaterKeepsTest extends TestCase
{
    /**
     * Where each writer writes: data (the data directory), bundles (the
     * tracking bundles), top (a file at the top of the OWA directory),
     * outside (outside the OWA directory: php://, the temporary directory, a
     * directory the operator names), helper (where its callers say), source
     * (a build tool, writing a checkout's shipped files), none (a method
     * named like a write).
     */
    private const WRITERS = [
        'Core/Controller/Cli.php'                            => ['outside'],
        'Core/Lib.php'                                       => ['helper'],
        'Core/PrivateFiles.php'                              => ['helper'],
        'Core/SafeFile.php'                                  => ['helper'],
        'Core/ServerRole.php'                                => ['outside'],
        'modules/Base/Classes/AutoUpdate/Downloads.php'      => ['data'],
        'modules/Base/Classes/AutoUpdate/InstallLock.php'    => ['top'],
        'modules/Base/Classes/AutoUpdate/Preflight.php'      => ['outside'],
        'modules/Base/Classes/AutoUpdate/Tar.php'            => ['outside'],
        'modules/Base/Classes/Backup.php'                    => ['outside'],
        'modules/Base/Classes/ConfigSecrets.php'             => ['top'],
        'modules/Base/Classes/Error.php'                     => ['data', 'outside'],
        'modules/Base/Classes/FileEventQueue.php'            => ['data'],
        'modules/Base/Classes/Settings.php'                  => ['top'],
        'modules/Base/Classes/TrackerBundle.php'             => ['bundles'],
        'modules/Base/Controller/StringsExtractCli.php'      => ['source'],
        'modules/Base/Controller/VersionInfoCli.php'         => ['source'],
        'modules/Base/Update/Update062.php'                  => ['data'],
        'modules/Base/Update/Update078.php'                  => ['none'],
        'modules/MaxmindGeoip/Controller/GeoipUpdateDbCli.php' => ['data', 'outside'],
        'updater.php'                                        => ['top', 'data'],
    ];

    /** A call that writes a file, or makes a directory. */
    private const WRITE = '/file_put_contents\(|SafeFile::(replace|create|append|makeDir)\(|[^_a-zA-Z>:$]mkdir\(|[^_a-zA-Z>:$]touch\(|[^_a-zA-Z>:$]fopen\([^;]*[\'"][wacx]|[^_a-zA-Z>:$]rename\(|[^_a-zA-Z>:$]symlink\(|[^_a-zA-Z>:$]copy\(|tempnam\(|makeSharedDir\(|extractTo\(/';

    public function testEveryWriterIsKnown(): void
    {
        $root  = Env::root();
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $path => $info) {
            $rel = substr((string) $path, strlen($root) + 1);

            if (! str_ends_with($rel, '.php') || preg_match('#^(\.|vendor/|tests/|node_modules/|owa-data/|public/)|/tests/#', $rel)) {
                continue;
            }

            if (preg_match(self::WRITE, (string) file_get_contents((string) $path))) {
                $found[] = $rel;
            }
        }

        sort($found);
        $this->assertSame(array_keys(self::WRITERS), $found,
            'A file of OWA\'s code writes files and is not in WRITERS, or no longer does: add it with where it writes, and keep that path in updater.php `keeps` if it is inside the OWA directory.');
    }

    /** What each writer writes in the OWA directory is kept: the data directory, the bundles, the marker and the lock. */
    public function testWhatOwaWritesIsKept(): void
    {
        $root  = Env::root();
        $u     = require $root . '/updater.php';
        $keeps = \OWA\Module\Base\Classes\AutoUpdate\Install::keeps($root);
        $kept  = fn (string $path): bool => $u['kept']($this->relative($root, $path), $keeps);

        $data = ['data' => Env::dataDir() . 'logs/errors.txt', 'data, as shipped' => $root . '/owa-data/queue/events.txt',
            'the updates directory' => Env::dataDir() . 'updates-' . str_repeat('a', 32) . '/backup-' . str_repeat('b', 32) . '/Core/Env.php'];
        foreach ($data as $what => $path) {
            if (str_starts_with($path, $root . '/')) {
                $this->assertTrue($kept($path), $what);
            }
        }

        // Where the bundles go in use: the test bootstrap points them elsewhere.
        $out                   = TrackerBundle::$outDir;
        TrackerBundle::$outDir = null;
        try {
            $this->assertTrue($kept((string) TrackerBundle::path('OWA-site')), 'a tracking bundle');
        } finally {
            TrackerBundle::$outDir = $out;
        }
        $this->assertSame('public/tracker/', TrackerBundle::DIR);
        $this->assertTrue($kept($u['marker']($root)), 'the install marker');
        $this->assertTrue($kept(InstallLock::path()), 'the install lock');
        $this->assertStringNotContainsString('/', $this->relative($root, (string) \OWA\Core\CoreAPI::getSetting('base', 'config_file')), 'owa-config.php is at the top');
    }

    /** OWA_DATA_DIR and OWA_PUBLIC_PATH inside the OWA directory: their files are kept too. */
    public function testTheConfiguredDirectoriesAreKept(): void
    {
        $u     = require Env::root() . '/updater.php';
        $root  = sys_get_temp_dir() . '/owa-keeps-' . bin2hex(random_bytes(4));
        mkdir($root . '/vendor/data', 0755, true);
        mkdir($root . '/public/alt', 0755, true);

        try {
            $keeps = $u['keeps']($root, $root . '/vendor/data/', $root . '/public/alt/');

            $this->assertSame(['owa-data', 'extensions', 'public/tracker', 'owa-update.php', 'owa-update-lock.php', 'vendor/data', 'public/alt/tracker'], $keeps);
            $this->assertTrue($u['kept']('vendor/data/logs/errors.txt', $keeps));
            $this->assertTrue($u['kept']('public/alt/tracker/OWA-site.js', $keeps));
            $this->assertFalse($u['kept']('public/alt/other.js', $keeps));
            $this->assertFalse($u['kept']('vendor/database.php', $keeps), 'a path is kept, not a prefix of a name');
            $this->assertSame(['owa-data', 'extensions', 'public/tracker', 'owa-update.php', 'owa-update-lock.php'], $u['keeps']($root, '/var/owa-data', ''), 'outside the OWA directory: nothing to keep');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    private function relative(string $root, string $path): string
    {
        $root = rtrim((string) (realpath($root) ?: $root), '/');
        $dir  = realpath(dirname($path)) ?: dirname($path);
        $path = rtrim((string) $dir, '/') . '/' . basename($path);

        $this->assertStringStartsWith($root . '/', $path, "$path is outside the OWA directory");

        return substr($path, strlen($root) + 1);
    }
}
