<?php

use OWA\Core\ConfigFile;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every owa-config.php the repository writes is one OWA reads
 * (Core\ConfigFile): constants only, no $this.
 *
 * The writers:
 * - the installer (Settings::createConfigFile());
 * - the e2e harnesses (selfhost_harness.php, used by the upgrade cycle and
 *   the isolation sweep; install_harness.php writecli, used by
 *   scratch_db_run.sh).
 *
 * Each builds the file from owa-config-dist.php plus string literals of its
 * own. So the template must pass, and no literal in a writer may hold $this.
 * The CI workflows and tests/tools scripts write no config lines of their own;
 * they are checked for $this too.
 */
final class ConfigGeneratorsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The writers, and the template each reads. */
    private const WRITERS = [
        'modules/Base/Classes/Settings.php',
        'tests/e2e/selfhost_harness.php',
        'tests/e2e/install_harness.php',
    ];

    public function testTheTemplateIsAccepted(): void
    {
        $this->assertSame([], ConfigFile::problems(self::ROOT . '/owa-config-dist.php'));
    }

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        return array_combine(self::WRITERS, array_map(static fn ($f) => [$f], self::WRITERS));
    }

    /** @dataProvider writers */
    public function testAWriterBuildsFromTheTemplate(string $file): void
    {
        $this->assertStringContainsString("owa-config-dist.php'", (string) file_get_contents(self::ROOT . '/' . $file));
    }

    /** @dataProvider writers */
    public function testNoStringAWriterWritesHoldsThis(string $file): void
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents(self::ROOT . '/' . $file)) as $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && strpos(stripslashes($t[1]), '$this') !== false) {
                $found[] = 'line ' . $t[2];
            }
        }

        $this->assertSame([], $found, "$file writes \$this into a config; use the setting's constant");
    }

    public function testTheScriptsAndWorkflowsWriteNoThis(): void
    {
        $files = array_merge(
            glob(self::ROOT . '/tests/tools/*.sh') ?: [],
            glob(self::ROOT . '/.github/workflows/*.yml') ?: []);

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('$this->', (string) file_get_contents($file), basename($file));
        }
    }
}
