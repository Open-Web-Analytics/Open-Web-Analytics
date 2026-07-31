<?php

use PHPUnit\Framework\TestCase;

/**
 * Update files must be discoverable, sequenceable and loadable.
 *
 * WHY THIS EXISTS
 * ---------------
 * The PSR-4 relocation renamed every update file from '<seq>.php' to
 * 'Update<seq>.php' -- necessary, because PSR-4 maps
 * OWA\Module\Base\Update\Update011 to Base/Update/Update011.php and '011' is
 * not a legal PHP class name. Two consumers of the old convention were not
 * renamed with them:
 *
 *   Core/Module.php    parsed the sequence as (int) substr($name, 0, -4).
 *                      (int) 'Update011' is 0, so the "is this newer than the
 *                      installed schema?" test compared 0 > 11 and NO update
 *                      ever qualified.
 *
 *   CoreAPI::updateFactory  built the legacy class name
 *                      'owa_base_Update011_update', which has not existed
 *                      since the relocation.
 *
 * The first short-circuited before the second, so nothing ever threw: the CLI
 * looped over an empty set and reported "Updates were applied." The update
 * mechanism was dead on every install, and said it had succeeded.
 *
 * These assertions are deliberately mechanical -- they are about the naming
 * contract between the filename, the class and the declared schema_version,
 * which is exactly what drifted.
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
        $obj = owa_coreAPI::updateFactory(strtolower($module), $class);

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
