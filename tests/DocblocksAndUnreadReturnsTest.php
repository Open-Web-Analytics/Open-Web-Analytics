<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Small corrections with no other test: a declared property, a return value
 * nothing reads, and docblocks that described something else.
 */
final class DocblocksAndUnreadReturnsTest extends TestCase
{
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

    /** Core\Controller has an errorAction(), as ReportController's docblock says. */
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
        $this->assertSame('a<b', \OWA\Module\Base\Classes\Sanitize::cleanInput('a<b', ['escape_html' => false]));
    }
}
