<?php

use PHPUnit\Framework\TestCase;

/**
 * Update files must be discoverable, sequenceable and loadable.
 *
 * WHY THIS EXISTS
 * ---------------
 * Update files are named 'Update<seq>.php', because PSR-4 maps
 * OWA\Module\Base\Update\Update011 to Base/Update/Update011.php and '011' is
 * not a legal PHP class name. Everything that reads the convention has to
 * agree with it: Core/Module.php parsing the sequence, and
 * CoreAPI::updateFactory building the class name. Parsed as (int) 'Update011',
 * every sequence is 0, no update ever qualifies, and the CLI reports "Updates
 * were applied." over an empty set.
 *
 * These assertions are deliberately mechanical -- they are about the naming
 * contract between the filename, the class and the declared schema_version.
 */
final class UpdateDiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @return array<string, array{0:string,1:string,2:int}> module, class, seq */
    public static function updateFileProvider(): array
    {
        $cases = [];

        foreach (glob(dirname(__DIR__) . '/modules/*/Update/Update*.php') ?: [] as $file) {
            $class  = basename($file, '.php');
            $module = basename(dirname(dirname($file)));

            if (! preg_match('/^Update(\d+)$/', $class, $m)) {
                continue;
            }

            $cases[$module . '/' . $class] = [$module, $class, (int) $m[1]];
        }

        return $cases;
    }

    /**
     * The filename must yield its sequence number. This is the exact parse that
     * silently returned 0 for every file.
     *
     * @dataProvider updateFileProvider
     */
    public function testFilenameYieldsItsSequenceNumber(string $module, string $class, int $seq): void
    {
        $this->assertGreaterThan(
            0,
            $seq,
            "$class must parse to a positive sequence. A parser returning 0 makes "
            . "'seq > current_schema_version' permanently false and silently disables updates."
        );
    }

    /**
     * The update must actually load, as the PSR-4 class, and declare a
     * schema_version matching its filename.
     *
     * @dataProvider updateFileProvider
     */
    public function testUpdateLoadsAndDeclaresMatchingSchemaVersion(string $module, string $class, int $seq): void
    {
        $obj = \OWA\Core\CoreAPI::updateFactory(strtolower($module), $class);

        $this->assertIsObject($obj, "updateFactory could not build $module/$class");
        $this->assertInstanceOf(
            \OWA\Core\Update::class,
            $obj,
            "$class must extend Core\\Update"
        );
        $this->assertSame(
            $seq,
            (int) $obj->schema_version,
            "$class declares schema_version {$obj->schema_version} but its filename says $seq. "
            . 'The two must agree or updates apply out of order.'
        );
    }

    /**
     * required_schema_version must cover the highest update on disk, or that
     * update can never run: Module::update() skips anything above it.
     */
    public function testRequiredSchemaVersionCoversEveryShippedUpdate(): void
    {
        $highest = 0;
        foreach (self::updateFileProvider() as [$module, $class, $seq]) {
            if (strtolower($module) === 'base' && $seq > $highest) {
                $highest = $seq;
            }
        }

        $src = file_get_contents(dirname(__DIR__) . '/modules/Base/Module.php');
        $this->assertSame(
            1,
            preg_match('/required_schema_version\s*=\s*(\d+)/', $src, $m),
            'could not read required_schema_version from Base/Module.php'
        );

        $this->assertGreaterThanOrEqual(
            $highest,
            (int) $m[1],
            "Base ships Update{$highest} but required_schema_version is {$m[1]}. "
            . 'Module::update() skips updates above required_schema_version, so it would never run.'
        );
    }
}
