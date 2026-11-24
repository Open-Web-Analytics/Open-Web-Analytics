<?php

use OWA\Module\Base\Classes\AutoUpdate\InstallNotices;
use PHPUnit\Framework\TestCase;

/** An automatic update's notices are e-mailed, so they name the install's directories rather than give their paths. */
final class InstallNoticesTest extends TestCase
{
    public function testPathsInTheOwaDirectoryAreGivenRelativeToIt(): void
    {
        $this->assertSame(
            'Could not write owa-data/updates-ab/owa_2.0.1_packaged.tar.part. Installing it needs about 40 MB free on the disk holding the OWA directory; 12 MB are.',
            InstallNotices::withoutPaths(
                'Could not write /srv/owa/owa-data/updates-ab/owa_2.0.1_packaged.tar.part. Installing it needs about 40 MB free on the disk holding /srv/owa; 12 MB are.',
                '/srv/owa', '/srv/owa/owa-data/'));
    }

    public function testADataDirectoryElsewhereIsNamed(): void
    {
        $this->assertSame(
            'Could not make the updates directory in the data directory. Could not write the data directory/updates-ab/last-failure.json. Core/Env.php was put back.',
            InstallNotices::withoutPaths(
                'Could not make the updates directory in /var/lib/owa. Could not write /var/lib/owa/updates-ab/last-failure.json. /srv/owa/Core/Env.php was put back.',
                '/srv/owa/', '/var/lib/owa/'));
    }
}
