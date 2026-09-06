<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The example module has to be an example that works.
 *
 * modules/Hello is what a third party copies to start a module, so a shape that
 * errors there is a shape that gets copied. Its navigation pointed at
 * hello.reportDashboard and hello.reportSearchterms, neither of which was
 * registered anywhere -- both links errored, and the module demonstrated
 * report navigation that cannot work.
 */
final class HelloModuleExampleTest extends TestCase
{
    private function helloModule()
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ( (array) $service->modules as $module ) {

            if ( $module->name === 'hello' || $module->group === 'hello' ) {

                return $module;
            }
        }

        return null;
    }

    /** Every report the example navigates to is a report it registers. */
    public function testItsNavigationOnlyNamesReportsItRegisters(): void
    {
        $module = $this->helloModule();

        if ( ! $module ) {
            $this->markTestSkipped( 'the hello module is not active.' );
        }

        $module->registerReports();
        $module->registerNavigation();

        // Reports register into the service map, not onto the module.
        $registered = array_keys( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'reports' ) );

        $this->assertNotEmpty( $registered,
            'the example module registers no reports, so its nav has nothing to point at.' );

        $checked = 0;

        foreach ( (array) $module->nav_links as $group ) {

            foreach ( (array) $group as $link ) {

                $ref = $link['ref'] ?? null;

                // A report link is the {do: base.report, reportId: x} shape.
                if ( ! is_array( $ref ) || ( $ref['do'] ?? '' ) !== 'base.report' ) {
                    continue;
                }

                $checked++;

                $this->assertContains( $ref['reportId'], $registered,
                    "the example module's nav points at report '{$ref['reportId']}', "
                  . 'which it does not register.' );
            }
        }

        $this->assertGreaterThan( 0, $checked,
            'no report link was examined, so this checked nothing.' );
    }

    /**
     * Its report definition is valid.
     *
     * A module ships a report as a JSON file, so a malformed one is a runtime
     * error rather than a parse error at build time.
     */
    public function testItsReportDefinitionIsValid(): void
    {
        $file = OWA_DIR . 'modules/Hello/reports/hello-dashboard.json';

        $this->assertFileExists( $file );

        $definition = json_decode( (string) file_get_contents( $file ), true );

        $this->assertIsArray( $definition,
            'the example report is not valid JSON: ' . json_last_error_msg() );

        $this->assertArrayHasKey( 'title', $definition );
        $this->assertArrayHasKey( 'widgets', $definition );
        $this->assertNotEmpty( $definition['widgets'] );
    }

    /**
     * A nav item drawn without an icon is a nav item that looks broken, and the
     * template renders the icon element either way.
     */
    public function testAReportNavItemGetsAnIconEvenWhenNoneIsNamed(): void
    {
        $module = $this->helloModule();

        if ( ! $module ) {
            $this->markTestSkipped( 'the hello module is not active.' );
        }

        $module->registerNavigation();

        $checked = 0;

        foreach ( (array) $module->nav_links as $group ) {

            foreach ( (array) $group as $link ) {

                $checked++;

                $this->assertNotSame( '', (string) ( $link['icon_class'] ?? '' ),
                    'a report nav item registered without an icon got an empty class, '
                  . 'so the nav draws an empty glyph beside it.' );
            }
        }

        $this->assertGreaterThan( 0, $checked, 'no nav link was examined.' );
    }
}
