<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The factories resolve a class by PSR-4 convention, from the module and the
 * directory, and by nothing else since v2.0.
 */
final class FactoryResolutionOrderTest extends TestCase
{
    /**
     * moduleFactory() resolves the module's own class. It once synthesized
     * owa_<action>Controller without the module, so acme.report could reach
     * OWA's Core\ReportController.
     */
    public function testAnActionResolvesToItsModulesController(): void
    {
        $this->assertInstanceOf(\OWA\Module\Base\Controller\Report::class,
            \OWA\Core\CoreAPI::moduleFactory( 'base.report', 'Controller', array() ) );
    }

    /** A name with no PSR-4 class is refused; no file is required in by name. */
    public function testAnActionWithNoClassIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/pre-PSR-4/');

        \OWA\Core\CoreAPI::moduleFactory( 'base.noSuchThing', 'Controller', array() );
    }

    public function testLibFactoryRefusesAClassThatIsNotThere(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/pre-PSR-4/');

        \OWA\Core\Lib::factory( OWA_BASE_DIR . '/modules/Base/Classes', 'owa_', 'no_such_class' );
    }

    /** Lib::factory derives the namespace from the directory it was given. */
    public function testLibFactoryResolvesFromTheDirectory(): void
    {
        $this->assertSame(
            'OWA\\Module\\Base\\Classes\\Event',
            \OWA\Core\Lib::conventionalClass( OWA_BASE_DIR . '/modules/Base/Classes/', 'owa_', 'event' ) );

        $this->assertSame(
            'OWA\\Core\\Db\\PdoMysql',
            \OWA\Core\Lib::conventionalClass( OWA_BASE_DIR . '/Core/Db/', 'owa_', 'pdo_mysql' ) );
    }

    /**
     * A name already carrying its prefix has no parts to work from.
     *
     * moduleFactory hands the whole built name in as $class_name, so the
     * convention has to decline rather than compute something from it.
     */
    public function testAnAlreadyBuiltNameIsDeclined(): void
    {
        $this->assertNull(
            \OWA\Core\Lib::conventionalClass( OWA_BASE_DIR . '/modules/Base/', '', 'owa_reportController' ) );
    }

    /** A directory outside the tree names no namespace. */
    public function testAnUnknownDirectoryResolvesNothing(): void
    {
        $this->assertNull(
            \OWA\Core\Lib::conventionalClass( '/tmp/somewhere', 'owa_', 'event' ) );
    }

    /** And a class that simply is not there resolves to null, not a guess. */
    public function testAMissingClassResolvesToNull(): void
    {
        $this->assertNull(
            \OWA\Core\Lib::conventionalClass( OWA_BASE_DIR . '/modules/Base/Classes/', 'owa_', 'no_such_class' ) );
    }
}
