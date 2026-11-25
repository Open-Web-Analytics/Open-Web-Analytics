<?php

use OWA\Module\Base\Classes\AutoUpdate\Tar;
use PHPUnit\Framework\TestCase;

/**
 * What Tar refuses: anything a release tarball is not. Archives are built
 * here, member by member, for what GNU tar will not make.
 */
final class TarTest extends TestCase
{
    /** A ustar member: header and data, padded to the block. */
    private function member(string $name, string $data, string $type = '0', ?string $size = null): string
    {
        $h = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad($size ?? sprintf('%011o', strlen($data)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0") . '        ' . $type
            . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $h . str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0");
    }

    /** @return resource */
    private function archive(string ...$members)
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, implode('', $members) . str_repeat("\0", 1024));
        rewind($h);

        return $h;
    }

    private function refuses(string $why, $archive): void
    {
        try {
            Tar::files($archive);
            $this->fail("read an archive with $why");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
    }

    public function testAWellFormedArchiveIsRead(): void
    {
        $this->assertSame(['a.php', 'b/c.php'], Tar::files($this->archive($this->member('./a.php', 'a'), $this->member('./b/', '', '5'), $this->member('./b/c.php', 'c'))));
    }

    public function testANameGivenTwiceIsRefused(): void
    {
        $this->refuses('holds a.php twice', $this->archive($this->member('./a.php', 'one'), $this->member('a.php', 'two')));
    }

    public function testAHeaderWhoseChecksumDoesNotMatchIsRefused(): void
    {
        $member = $this->member('./a.php', 'a');
        $member[0] = 'x';
        $this->refuses('checksum does not match', $this->archive($member));
    }

    public function testAMalformedSizeIsRefused(): void
    {
        $this->refuses('malformed header', $this->archive($this->member('./a.php', 'a', '0', '0000000009x')));
    }

    public function testALongNameOrPaxRecordLargerThanAnyReleaseIsRefused(): void
    {
        foreach (['L', 'x', 'g'] as $type) {
            $this->refuses('header record of', $this->archive($this->member('././@LongLink', '', $type, sprintf('%011o', Tar::MEMBER_MAX + 1))));
        }
    }

    public function testAGlobalPaxHeaderIsSkipped(): void
    {
        $this->assertSame(['a.php'], Tar::files($this->archive($this->member('pax_global_header', "20 comment=anything\n", 'g'), $this->member('./a.php', 'a'))));
    }

    public function testAMemberReadWholeLargerThanTheLimitIsRefused(): void
    {
        $this->expectExceptionMessage('larger than ' . Tar::MEMBER_MAX . ' bytes');
        Tar::member($this->archive($this->member('./release.php', str_repeat('x', Tar::MEMBER_MAX + 1))), 'release.php');
    }

    public function testAnArchiveWithoutItsEndMarkerIsRefused(): void
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, $this->member('./a.php', 'a'));
        rewind($h);
        $this->refuses('ends before its end-of-archive marker', $h);
    }
}
