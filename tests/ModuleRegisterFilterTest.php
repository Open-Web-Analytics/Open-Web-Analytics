<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** A filter handler named by its class. */
final class ModuleRegisterFilterTestHandler
{
    public function shout( $value ) {

        return strtoupper( $value );
    }
}

/**
 * Module::registerFilter() takes a handler class by name.
 *
 * Given a class that exists, it built nothing and registered array(null,
 * $method), so the filter never ran.
 */
final class ModuleRegisterFilterTest extends TestCase
{
    public function testAHandlerNamedByItsClassIsRun(): void
    {
        $module = new class extends \OWA\Core\Module {

            // Only registerFilter() is under test, not a module's registrations.
            function __construct() {

                $this->name = 'base';
            }
        };

        $module->registerFilter( 'module_register_filter_test', ModuleRegisterFilterTestHandler::class, 'shout' );

        $this->assertSame( 'QUIET', \OWA\Core\CoreAPI::filter( 'module_register_filter_test', 'quiet' ) );
    }
}
