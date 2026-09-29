<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The release tarball ships no module's unbuilt JS source.
 *
 * webpack compiles every modules/<Dir>/src into public/base/dist, and the
 * packaging step names each src directory by exact path (a glob would match
 * across slashes). A module that gains a src directory has to be added there.
 */
final class ReleaseExcludesModuleSourceTest extends TestCase
{
    public function testEveryModuleSourceDirectoryIsExcluded(): void
    {
        $workflow = (string) file_get_contents(OWA_DIR . '.github/workflows/main.yml');

        $dirs = glob(OWA_DIR . 'modules/*/src', GLOB_ONLYDIR);

        $this->assertContains(OWA_DIR . 'modules/Base/src', $dirs, 'no source directories were found');

        foreach ($dirs as $dir) {
            $relative = './' . substr($dir, strlen(OWA_DIR));

            $this->assertStringContainsString("--exclude='$relative'", $workflow,
                "$relative would ship in the release tarball");
        }
    }
}
