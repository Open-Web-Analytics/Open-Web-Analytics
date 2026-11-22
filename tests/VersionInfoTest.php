<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\VersionInfo;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * release.json (Core\VersionInfo), held to what the booted code says: each
 * module's schema as its constructed module has it, and every update as
 * Module::update() would build it.
 */
final class VersionInfoTest extends TestCase
{
    public function testItsShape(): void
    {
        $info = VersionInfo::toArray();

        $this->assertSame(['format', 'version', 'branch', 'requirements', 'modules', 'updates'], array_keys($info));
        $this->assertSame(1, $info['format']);
        $this->assertSame(\OWA\Core\Env::version(), $info['version']);
        $this->assertSame(\OWA\Core\Requirements::toArray(), $info['requirements']);
        $this->assertSame('base', array_key_first($info['modules']));

        foreach ($info['updates'] as $update) {
            $this->assertSame(['module', 'sequence', 'manual_install'], array_keys($update));
        }
    }

    public function testTheBranchIsTheMajorVersion(): void
    {
        $this->assertSame('2.x', VersionInfo::branch('2.0.3'));
        $this->assertSame('1.x', VersionInfo::branch('1.14.10'));
        $this->assertNull(VersionInfo::branch('master'), 'a checkout');
    }

    /** Read as declared defaults, the same as each constructed module has them. */
    public function testEachModuleIsAsItsModuleSays(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        foreach (VersionInfo::toArray()['modules'] as $name => $required) {
            $dir    = \OWA\Core\Lib::moduleDirName($name);
            $class  = '\\OWA\\Module\\' . $dir . '\\Module';
            $module = $s->getModule($name) ?: new $class();

            $this->assertSame($name, $module->name);
            $this->assertSame((int) $module->required_schema_version, $required, $name);
        }

        $this->assertSame(
            ['base', 'domstream', 'maxmind_geoip', 'sqs'],
            array_keys(VersionInfo::toArray()['modules']),
            'every module directory shipped');
    }

    /** Every update Module::update() would build, with its own $manual_install. */
    public function testEveryUpdateIsAsItsClassSays(): void
    {
        $info    = VersionInfo::toArray();
        $listed  = [];

        foreach ($info['updates'] as $u) {
            $listed[$u['module']][$u['sequence']] = $u;
        }

        foreach ($info['modules'] as $name => $required) {
            $expected = array_keys(array_filter(\OWA\Core\Module::updateClasses($name),
                static fn ($seq) => $seq <= $required, ARRAY_FILTER_USE_KEY));

            $this->assertSame($expected, array_keys($listed[$name] ?? []), $name);

            foreach ($listed[$name] ?? [] as $seq => $u) {
                $update = \OWA\Core\CoreAPI::updateFactory($name, sprintf('Update%03d', $seq));

                $this->assertSame((string) $update->manual_install, $u['manual_install'], "$name $seq");
            }
        }

        $base = array_keys($listed['base']);
        $this->assertSame(range($base[0], (int) $info['modules']['base']), $base, "Base's run unbroken to its schema");
    }

    /** cmd=version-info --file writes it whole. */
    public function testTheCommandWritesTheFile(): void
    {
        $file = sys_get_temp_dir() . '/owa-release-' . bin2hex(random_bytes(4)) . '.json';

        try {
            ob_start();
            $code = \OWA\Module\Base\Controller\VersionInfoCli::runWithoutBoot(['cmd' => 'version-info', 'file' => $file]);
            ob_end_clean();

            $this->assertSame(0, $code);
            $this->assertSame(VersionInfo::toArray(), json_decode((string) file_get_contents($file), true));
            $this->assertSame([], glob($file . '.tmp*'), 'no temporary file left');
        } finally {
            @unlink($file);
        }
    }
}
