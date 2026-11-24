<?php

use OWA\Core\SafeFile;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * SafeFile in a directory another user writes: a link put at a name, before
 * or during a write, never leads the write to the file it names.
 */
final class SafeFileTest extends TestCase
{
    private string $dir = '';
    private string $victim = '';

    protected function setUp(): void
    {
        $this->dir    = sys_get_temp_dir() . '/owa-safe-file-' . bin2hex(random_bytes(4));
        $this->victim = $this->dir . '/victim.php';
        mkdir($this->dir . '/shared', 0777, true);
        file_put_contents($this->victim, 'the writer\'s');
        chmod($this->victim, 0640);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** create() never opens a file that is there, and makes its file with the mode asked for, whatever the umask. */
    public function testCreate(): void
    {
        symlink($this->victim, $this->dir . '/shared/link');
        $this->assertFalse(SafeFile::create($this->dir . '/shared/link', 0600));
        $this->assertNotSame(SafeFile::fresh('a'), SafeFile::fresh('a'), 'names made afresh: PHP follows a link, a dangling one too, to open a name');
        $this->assertMatchesRegularExpression('#^a-[0-9a-f]{32}$#', SafeFile::fresh('a'));

        $umask = umask(0077);
        try {
            $h = SafeFile::create($this->dir . '/shared/new', 0644);
        } finally {
            umask($umask);
        }
        $this->assertIsResource($h);
        fclose($h);
        $this->assertSame('0644', $this->mode($this->dir . '/shared/new'));
        $this->assertSame('the writer\'s', file_get_contents($this->victim));
    }

    /** replace() puts a new file in place of a link, and leaves no temporary file. */
    public function testReplace(): void
    {
        $path = $this->dir . '/shared/state.json';
        symlink($this->victim, $path);

        SafeFile::replace($path, '{}');

        $this->assertFalse(is_link($path));
        $this->assertSame('{}', file_get_contents($path));
        $this->assertSame('0644', $this->mode($path));
        $this->assertSame('the writer\'s', file_get_contents($this->victim));
        $this->assertSame('0640', $this->mode($this->victim));
        $this->assertSame(['state.json'], $this->names());

        $this->expectException(\RuntimeException::class);
        SafeFile::replace($this->dir . '/missing/state.json', '{}');
    }

    /** append() makes the file with its mode, whatever the umask, and adds to it after. */
    public function testAppend(): void
    {
        $path  = $this->dir . '/shared/errors.txt';
        $umask = umask(0022);
        try {
            $this->assertTrue(SafeFile::append($path, "one\n", 0664));
            $this->assertTrue(SafeFile::append($path, "two\n", 0664));
        } finally {
            umask($umask);
        }

        $this->assertSame("one\ntwo\n", file_get_contents($path));
        $this->assertSame('0664', $this->mode($path), 'both users append: group-writable');
        $this->assertSame(['errors.txt'], $this->names());
        $this->assertFalse(SafeFile::append($this->dir . '/missing/errors.txt', "one\n"));
    }

    /** A link at the name, a dangling one, a hard link to another file, a FIFO or a directory: nothing is written, nor any file made where a link points. */
    public function testAppendRefusesWhatIsNotItsOwnPlainFile(): void
    {
        $path = $this->dir . '/shared/errors.txt';

        symlink($this->victim, $path);
        $this->assertFalse(SafeFile::append($path, "line\n"));
        unlink($path);

        symlink($this->dir . '/made-there', $path);
        $this->assertFalse(SafeFile::append($path, "line\n"));
        $this->assertFileDoesNotExist($this->dir . '/made-there');
        unlink($path);

        link($this->victim, $path);
        $this->assertFalse(SafeFile::append($path, "line\n"));
        unlink($path);

        posix_mkfifo($path, 0666);
        $this->assertFalse(SafeFile::append($path, "line\n"), 'not opened, so it does not block');
        unlink($path);

        mkdir($path);
        $this->assertFalse(SafeFile::append($path, "line\n"));

        $this->assertSame('the writer\'s', file_get_contents($this->victim));
        $this->assertSame('0640', $this->mode($this->victim));
    }

    /** A link swapped in after the name was checked is told by the inode of what was opened. */
    public function testAFileSwappedForALinkIsNotWritten(): void
    {
        $path = $this->dir . '/shared/errors.txt';
        file_put_contents($path, '');

        $h = fopen($this->victim, 'ab');
        $isNamed = new \ReflectionMethod(SafeFile::class, 'isNamed');
        $this->assertFalse($isNamed->invoke(null, $h, $path), 'what was opened is not what the name holds');
        fclose($h);

        $h = fopen($path, 'ab');
        $this->assertTrue($isNamed->invoke(null, $h, $path));
        fclose($h);
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return sprintf('%04o', fileperms($path) & 0777);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_values(array_diff((array) scandir($this->dir . '/shared'), ['.', '..']));
    }
}
