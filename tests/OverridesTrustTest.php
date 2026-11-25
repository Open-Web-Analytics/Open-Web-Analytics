<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Overrides;
use OWA\Core\SafeFile;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Overrides of conf/ files are PHP the cron user runs, so they live in the
 * data directory's conf/ and are run only when nobody but the owner of OWA's
 * files could have written them: the file, owa-data/conf/ and owa-data/ each
 * belong to that owner (or root) and none is writable by group or others
 * (SafeFile::trustProblem()). The web server writes only owa-data/logs/.
 *
 * Run in a temporary data directory, made by this process, which owns OWA's
 * files where the tests run.
 */
final class OverridesTrustTest extends TestCase
{
    /** @var string */
    private $data;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('no file modes on Windows');
        }

        if (fileowner(__FILE__) !== SafeFile::codeOwner()) {
            $this->markTestSkipped('the tests run as a user other than the owner of OWA\'s files');
        }

        $this->data = sys_get_temp_dir() . '/owa-overrides-' . bin2hex(random_bytes(4)) . '/';

        $old = umask(022);
        mkdir($this->data . 'conf', 0755, true);
        file_put_contents($this->data . 'conf/searchengines.php', "<?php return ['example' => ['name' => 'Example']];");
        umask($old);
    }

    protected function tearDown(): void
    {
        if ($this->data === null) {
            return;
        }

        foreach (['conf/searchengines.php', 'conf/link.php', 'searchengines.php', 'channels.php'] as $f) {
            @unlink($this->data . $f);
        }

        @chmod($this->data, 0755);
        @rmdir($this->data . 'conf');
        @rmdir($this->data);
    }

    private function override(): string
    {
        return $this->data . 'conf/searchengines.php';
    }

    public function testAnOverrideOnlyItsOwnerCanWriteIsRun(): void
    {
        $this->assertNull(SafeFile::trustProblem($this->override(), $this->data));
        $this->assertSame($this->override(), Overrides::file('searchengines.php', $this->data));
        $this->assertSame([], Overrides::skipped($this->data));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function writableByOthers(): array
    {
        return [
            'the data directory'    => ['', 0775],
            'its conf/'             => ['conf', 0777],
            'the file'              => ['conf/searchengines.php', 0664],
        ];
    }

    /** @dataProvider writableByOthers */
    public function testAnOverrideOthersCouldHaveWrittenIsSkipped(string $path, int $mode): void
    {
        chmod(rtrim($this->data . $path, '/'), $mode);

        $this->assertNull(Overrides::file('searchengines.php', $this->data));
        $this->assertStringContainsString('is writable by group or others', (string) SafeFile::trustProblem($this->override(), $this->data));
        $this->assertArrayHasKey($this->override(), Overrides::skipped($this->data));
    }

    /** A link under the data directory is never followed; one an admin made at the data directory itself is. */
    public function testALinkIsRefusedBelowTheDataDirectoryOnly(): void
    {
        symlink($this->override(), $this->data . 'conf/link.php');

        $this->assertStringContainsString('is a link', (string) SafeFile::trustProblem($this->data . 'conf/link.php', $this->data));

        $mounted = sys_get_temp_dir() . '/owa-overrides-link-' . bin2hex(random_bytes(4));
        symlink(rtrim($this->data, '/'), $mounted);

        try {
            $this->assertNull(SafeFile::trustProblem($mounted . '/conf/searchengines.php', $mounted . '/'));
        } finally {
            unlink($mounted);
        }
    }

    public function testAFileOutsideTheDataDirectoryIsRefused(): void
    {
        $this->assertStringContainsString('is not under', (string) SafeFile::trustProblem($this->override(), '/nowhere/'));
    }

    /** Where OWA read them before 2.0, the data directory itself: not read, and named. */
    public function testAnOverrideInTheDataDirectoryItselfIsNotReadAndNamed(): void
    {
        file_put_contents($this->data . 'channels.php', "<?php return [];");

        $this->assertNull(Overrides::file('channels.php', $this->data));
        $this->assertSame([$this->data . 'channels.php'], Overrides::misplaced($this->data));
    }

    public function testSystemHealthNamesWhatIsNotUsedAndWhatToMove(): void
    {
        $this->assertNull(\OWA\Module\Base\Classes\SystemHealth::overrides($this->data));

        chmod($this->data, 0775);
        file_put_contents($this->data . 'channels.php', "<?php return [];");

        $finding = \OWA\Module\Base\Classes\SystemHealth::overrides($this->data);

        $this->assertSame('yellow', $finding['level']);
        $this->assertStringContainsString($this->override() . ' is not used: ', $finding['detail']);
        $this->assertStringContainsString('Move them to ' . $this->data . 'conf/', $finding['detail']);
    }
}
