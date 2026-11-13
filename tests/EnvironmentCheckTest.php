<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\EnvironmentCheck;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The server requirements EnvironmentCheck asks about beyond the PHP version:
 * 64-bit integers, zlib, and a database driver that can load.
 *
 * Every machine that runs the suite is 64-bit with pdo_mysql, mysqli and
 * mysqlnd, so the failing answers are asked of the pure checks directly.
 */
final class EnvironmentCheckTest extends TestCase
{
    private static function named(string $name): array
    {
        foreach (EnvironmentCheck::all(OWA_DIR . 'owa-config.php') as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        self::fail("no '$name' check");
    }

    public function testA64BitBuildPasses(): void
    {
        $check = EnvironmentCheck::integerSize(8);

        $this->assertTrue($check['passed']);
        $this->assertSame('64-bit', $check['value']);
        $this->assertSame('', $check['msg']);
    }

    public function testA32BitBuildFailsAndSaysWhatToDo(): void
    {
        $check = EnvironmentCheck::integerSize(4);

        $this->assertFalse($check['passed']);
        $this->assertSame('32-bit', $check['value']);
        $this->assertStringContainsString('64-bit build of PHP', $check['msg']);
    }

    public function testTheIntegerSizeIsOneOfTheChecks(): void
    {
        $this->assertSame(PHP_INT_SIZE === 8, self::named('64-bit PHP')['passed']);
    }

    public function testZlibIsARequiredExtension(): void
    {
        $this->assertArrayHasKey('zlib', EnvironmentCheck::REQUIRED_EXTENSIONS);
        $this->assertSame(extension_loaded('zlib'), self::named('PHP extension: zlib')['passed']);
    }

    public function testPdoAloneIsEnough(): void
    {
        $check = EnvironmentCheck::databaseDriver(true, false, false);

        $this->assertTrue($check['passed']);
        $this->assertSame('pdo_mysql', $check['value']);
    }

    public function testPdoCoversAMysqliWithoutMysqlnd(): void
    {
        // The default type loads PDO wherever it is present.
        $check = EnvironmentCheck::databaseDriver(true, true, false);

        $this->assertTrue($check['passed']);
        $this->assertSame('pdo_mysql, mysqli (without mysqlnd)', $check['value']);
    }

    public function testMysqliOnMysqlndIsEnough(): void
    {
        $check = EnvironmentCheck::databaseDriver(false, true, true);

        $this->assertTrue($check['passed']);
        $this->assertSame('mysqli', $check['value']);
    }

    public function testMysqliWithoutMysqlndFails(): void
    {
        $check = EnvironmentCheck::databaseDriver(false, true, false);

        $this->assertFalse($check['passed']);
        $this->assertSame('mysqli (without mysqlnd)', $check['value']);
        $this->assertStringContainsString('without mysqlnd', $check['msg']);
    }

    public function testAForcedMysqliNeedsMysqlndEvenWithPdoPresent(): void
    {
        $check = EnvironmentCheck::databaseDriver(true, true, false, 'mysqli');

        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('without mysqlnd', $check['msg']);
    }

    public function testAForcedMysqliThatIsNotLoadedFails(): void
    {
        $check = EnvironmentCheck::databaseDriver(true, false, false, 'mysqli');

        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('mysqli extension is not loaded', $check['msg']);
    }

    public function testAForcedPdoThatIsNotLoadedFails(): void
    {
        $check = EnvironmentCheck::databaseDriver(false, true, true, 'pdo');

        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('pdo_mysql extension is not loaded', $check['msg']);
    }

    public function testNoDriverAtAllFails(): void
    {
        $check = EnvironmentCheck::databaseDriver(false, false, false);

        $this->assertFalse($check['passed']);
        $this->assertSame('none found', $check['value']);
        $this->assertStringContainsString('pdo_mysql or mysqli', $check['msg']);
    }

    public function testAnEmptyTypeIsTheDefault(): void
    {
        $this->assertFalse(EnvironmentCheck::databaseDriver(false, true, false, '')['passed']);
    }

    public function testAThirdPartyDriverIsNotJudged(): void
    {
        $this->assertTrue(EnvironmentCheck::databaseDriver(true, false, false, 'acme')['passed']);
    }

    public function testThisServersDriverCheckPasses(): void
    {
        $this->assertTrue(self::named('Database driver')['passed']);
    }
}
