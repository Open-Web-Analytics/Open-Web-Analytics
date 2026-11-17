<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Small corrections with no other test: a declared property, a return value
 * nothing reads, and docblocks that described something else.
 */
final class DocblocksAndUnreadReturnsTest extends TestCase
{
    /** singleton() keeps what it is given in a declared property, not a dynamic one. */
    public function testCoreApiDeclaresParams(): void
    {
        $this->assertTrue(property_exists(\OWA\Core\CoreAPI::class, 'params'));

        $api = \OWA\Core\CoreAPI::singleton();
        $was = $api->params;
        $deprecations = array();

        set_error_handler(function ($no, $msg) use (&$deprecations) {
            $deprecations[] = $msg;

            return true;
        }, E_DEPRECATED);

        try {
            \OWA\Core\CoreAPI::singleton(array('probe' => 'zz'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(array('probe' => 'zz'), $api->params);
        $this->assertSame(array(), $deprecations);

        $api->params = $was;
    }

    /** With no subview to load it answers nothing, whatever the debug log answered. */
    public function testLoadSubViewAnswersNothing(): void
    {
        $view = (new ReflectionClass(\OWA\Core\View::class))->newInstanceWithoutConstructor();
        $view->e = new class {
            public function debug($message)
            {
                return true;
            }
        };

        $this->assertNull($view->loadSubView(null));
    }

    /** The constant's docblock is its own, not the end of an older copy of isTrackingEventType()'s. */
    public function testTheDispatchNamespaceDocblockIsItsOwn(): void
    {
        $doc = (string) (new ReflectionClassConstant(\OWA\Core\CoreAPI::class, 'TRACKING_DISPATCH_NAMESPACE'))->getDocComment();

        $this->assertStringStartsWith("/**\n     * The namespace every tracking event is dispatched under.", $doc);
        $this->assertSame(1, substr_count($doc, '/**'));
    }

    /** Core\Controller has an errorAction(); ReportController's docblock no longer says otherwise. */
    public function testReportControllerErrorActionDocblock(): void
    {
        $this->assertTrue(method_exists(\OWA\Core\Controller::class, 'errorAction'));

        $doc = (string) (new ReflectionMethod(\OWA\Core\ReportController::class, 'errorAction'))->getDocComment();

        $this->assertStringNotContainsString('has no errorAction()', $doc);
    }

    /** cleanInput() reads $options. */
    public function testCleanInputDocblockSaysOptionsAreRead(): void
    {
        $doc = (string) (new ReflectionMethod(\OWA\Module\Base\Classes\Sanitize::class, 'cleanInput'))->getDocComment();

        $this->assertStringNotContainsString('$options not read', $doc);
        $this->assertSame('a&lt;b', \OWA\Module\Base\Classes\Sanitize::cleanInput('a<b'));
        $this->assertSame('a<b', \OWA\Module\Base\Classes\Sanitize::cleanInput('a<b', array('escape_html' => false)));
    }
}
