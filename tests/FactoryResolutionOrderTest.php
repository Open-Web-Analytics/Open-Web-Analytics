<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every factory resolves PSR-4 first, and the compat map only after.
 *
 * The map exists for names third parties once called. It is not a lookup table
 * OWA reads to find its own classes, and while it was one, a class added after
 * the namespace migration could not be constructed without an entry in a file
 * that is deleted at 2.0.
 *
 * These assert the ORDER, not merely that resolution works: with the map still
 * present both routes answer for most names, so a test that only checked the
 * result would pass either way.
 */
final class FactoryResolutionOrderTest extends TestCase
{
    /**
     * The one name the two orders disagree about.
     *
     * moduleFactory() builds its class name as $class_ns . $file . $suffix --
     * WITHOUT the module -- so any module with an action named 'report'
     * synthesizes owa_reportController. The map sends that to OWA's own base
     * class; the convention sends it to the module's controller.
     *
     * base.report is a registered action, so OWA never reaches this path. The
     * callers who do are third-party modules, and for them the module's own
     * controller is the right answer.
     */
    public function testTheNameTheTwoOrdersDisagreeAboutResolvesByConvention(): void
    {
        $this->assertSame(
            'OWA\\Core\\ReportController',
            \OWA\Core\Lib::resolveNamespacedClass( 'owa_reportController' ),
            'the map still says what it always said; this test is about which one wins' );

        $obj = \OWA\Core\CoreAPI::moduleFactory( 'base.report', 'Controller', array() );

        $this->assertInstanceOf(
            \OWA\Module\Base\Controller\Report::class,
            $obj,
            'PSR-4 must win: resolving to Core\\ReportController would hand a module '
          . "OWA's base class instead of the controller it named" );
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
