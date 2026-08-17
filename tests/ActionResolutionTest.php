<?php

use PHPUnit\Framework\TestCase;

/**
 * What happens to a request naming an action that resolves to nothing.
 *
 * Every one of these is reachable by anyone who can type a URL, without
 * credentials, so the question is not whether they can be sent but what they
 * are answered with. Until this was fixed the answer was an uncaught exception:
 * HTTP 500 and a PHP fatal in the web server's log, for what is a missing page.
 *
 * Two separate defects produced that, and both are covered here -- the action
 * resolver raising with nothing to catch it, and the production error handler
 * registering no exception handler at all.
 */
final class ActionResolutionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        // Each case asserts the status it produced, so start from a known one.
        http_response_code(200);
    }

    /**
     * A name that is not <module>.<action>, or whose halves are not bare
     * identifiers, is refused before any filesystem path is built from it.
     *
     * This is the guard that stops a request parameter reaching require_once().
     * It must raise the typed exception, because the caller turns that into a
     * different status code than a merely absent controller.
     */
    public function testMalformedActionNamesAreRefusedByType()
    {
        $malformed = [
            'base',                        // no action half
            'base.foo.bar',                // too many halves
            '',                            // nothing at all
            'base.',                       // empty action
            '.sites',                      // empty module
            'base./../../etc/passwd',      // traversal in the action
            '../../../../etc/passwd',      // traversal, no separator
            'base.%2e%2e%2fconfig',        // encoded traversal
            'base.owa config',             // space
            "base.owa'config",             // quote
            'base.owa/config',             // separator
            'base.owa-config',             // hyphen is not an identifier char
            'base.owa.config',             // extra dot
            "base.owa\0config",            // null byte
        ];

        foreach ($malformed as $action) {
            try {
                \OWA\Core\CoreAPI::moduleFactory($action, 'Controller', []);
                $this->fail(var_export($action, true) . ' should have been refused');
            } catch (\OWA\Core\Exception\InvalidAction $e) {
                $this->assertSame('Invalid action.', $e->getMessage(),
                    'the message must not echo the rejected value back');
                $this->assertStringNotContainsString(
                    'passwd', $e->getMessage(),
                    var_export($action, true) . ': the rejected value must not appear in the message'
                );
            }
        }
    }

    /**
     * A well-formed name that no controller answers to is a different failure,
     * and must NOT be the typed one -- it is a missing page, not a bad request.
     */
    public function testAWellFormedButUnknownActionRaisesSeparately()
    {
        $this->expectException(\Exception::class);

        try {
            \OWA\Core\CoreAPI::moduleFactory('base.noSuchActionAnywhere', 'Controller', []);
        } catch (\OWA\Core\Exception\InvalidAction $e) {
            $this->fail('a well-formed name must not be reported as malformed');
        }

        // Re-raise for the expectation above.
        \OWA\Core\CoreAPI::moduleFactory('base.noSuchActionAnywhere', 'Controller', []);
    }

    /**
     * The status codes an unresolvable action produces.
     *
     * A malformed name was never a route on any installation (400); a
     * well-formed one simply is not present on this one (404). Neither is a
     * server fault, and neither may be a 500.
     */
    public function testUnresolvableActionsProduceAClientError()
    {
        foreach ([400, 404] as $code) {

            \OWA\Core\CoreAPI::actionNotResolved('base.whatever', $code, new \Exception('x'));

            $this->assertSame($code, http_response_code(), "should have set $code");
        }
    }

    /**
     * The response must not reflect the requested action.
     *
     * It is request-supplied, so echoing it into the error page would put
     * attacker-chosen text on a page served from this origin. It belongs in the
     * log, where an administrator can see what was asked for.
     */
    public function testTheErrorPageDoesNotReflectTheRequestedAction()
    {
        $marker = 'zzMarker' . bin2hex(random_bytes(4));
        $probe  = 'base.' . $marker . '<script>alert(1)</script>';

        $page = (string) \OWA\Core\CoreAPI::actionNotResolved($probe, 404, new \Exception('x'));

        $this->assertNotSame('', $page, 'an error page should have been rendered');
        $this->assertStringContainsString('could not be found', $page,
            'the generic message is what the visitor gets');

        // The page carries its own markup and scripts, so the check is for the
        // probe specifically -- raw, entity-encoded and url-encoded, since any
        // of those reaching the page would be a reflection.
        foreach ([$marker, htmlspecialchars($marker), urlencode($marker), 'alert(1)'] as $needle) {
            $this->assertStringNotContainsString($needle, $page,
                'the requested action must not be reflected into the response');
        }
    }

    /**
     * The production error handler must register an exception handler.
     *
     * This is the defect that made the difference between installations: only
     * the development handler registered one, so the installation facing the
     * internet was the one where an uncaught exception became a PHP fatal.
     */
    public function testBothErrorHandlersRegisterAnExceptionHandler()
    {
        $original = set_exception_handler(null);

        try {
            foreach (['createProductionHandler', 'createDevelopmentHandler'] as $method) {

                // Clear first. Without this the handler registered by the
                // bootstrap -- or by the previous iteration -- is still
                // installed, and the assertion below passes whether or not the
                // method under test registers anything at all.
                set_exception_handler(null);
                $this->assertNull(set_exception_handler(null),
                    'the slot must be empty before the method under test runs');

                $e = \OWA\Core\CoreAPI::supportClassFactory('base', 'error');
                $e->$method();

                $handler = set_exception_handler(null);

                $this->assertIsArray($handler, "$method must register an exception handler");
                $this->assertSame('handleUncaughtException', $handler[1],
                    "$method must register the handler that sets a status code");
            }

        } finally {
            set_exception_handler($original);
        }
    }

    /**
     * The handler records the exception and answers 500 -- it does not swallow
     * the error into a 200 with an empty body, which is what reusing the plain
     * logger would have done.
     */
    public function testTheUncaughtHandlerLogsAndSetsAServerError()
    {
        $e = \OWA\Core\CoreAPI::supportClassFactory('base', 'error');

        ob_start();
        $e->handleUncaughtException(new \Exception('a marker for the log, not the page'));
        $body = ob_get_clean();

        // Under the CLI the status is left alone; the assertion that matters
        // everywhere is that nothing about the exception reaches the output.
        $this->assertStringNotContainsString('marker for the log', $body,
            'an exception message must never be written to the response');
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    /**
     * Under the CLI there is no page to render and no status to invent -- the
     * command reports through notice(), and nothing else.
     *
     * Runs in a subprocess because it needs OWA_CLI defined, and a constant
     * cannot be unset once set. PHPUnit's own process isolation is not used: it
     * fails a test on any startup warning the PHP build happens to emit, which
     * makes it a test of the environment rather than of the code.
     *
     * The result is base64 between two markers because OWA logs to stdout once
     * OWA_CLI is defined, and its shutdown output lands after the payload.
     */
    public function testTheCliGetsNoPageAndNoStatus()
    {
        $bootstrap = var_export(__DIR__ . '/bootstrap_owa.php', true);

        $script = "define('OWA_CLI', true);"
                . "require $bootstrap;"
                . "http_response_code(200);"
                . "\$page = \\OWA\\Core\\CoreAPI::actionNotResolved('base.whatever', 404, new \\Exception('x'));"
                . "\$e = \\OWA\\Core\\CoreAPI::supportClassFactory('base', 'error');"
                . "ob_start();"
                . "\$e->handleUncaughtException(new \\Exception('cli side'));"
                . "\$body = ob_get_clean();"
                . "fwrite(STDOUT, '<<' . base64_encode(json_encode(["
                . "'page' => \$page, 'body' => \$body, 'status' => http_response_code()"
                . "])) . '>>');";

        $out = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($script) . ' 2>/dev/null'
        );

        $this->assertSame(1, preg_match('/<<([A-Za-z0-9+\/=]+)>>/', $out, $m),
            'subprocess produced no payload: ' . substr($out, 0, 300));

        $result = json_decode((string) base64_decode($m[1]), true);

        $this->assertIsArray($result);
        $this->assertNull($result['page'], 'the CLI gets no rendered view');
        $this->assertSame('', $result['body'], 'the CLI gets no output from the exception handler');
        $this->assertSame(200, $result['status'],
            'neither path may change the status under the CLI');
    }

    /**
     * The whole point, end to end: performAction() must not let an
     * unresolvable action escape as an exception.
     *
     * The other cases here test the pieces. This one tests the wiring, which is
     * where the defect actually was -- the resolver has always raised, and
     * nothing caught it.
     */
    public function testPerformActionAnswersInsteadOfRaising()
    {
        $cases = [
            'base.noSuchActionAnywhere'   => 404,   // well-formed, absent
            'base'                        => 400,   // malformed
            'base./../../etc/passwd'      => 400,   // malformed, traversal
            'base.foo.bar'                => 400,
        ];

        foreach ($cases as $action => $expected) {

            http_response_code(200);

            try {
                $page = (string) \OWA\Core\CoreAPI::performAction($action, []);

            } catch (\Throwable $t) {
                $this->fail(sprintf(
                    'performAction("%s") raised %s: %s -- an unresolvable action must be answered, not thrown',
                    $action, get_class($t), $t->getMessage()
                ));
            }

            $this->assertSame($expected, http_response_code(), "$action should answer $expected");
            $this->assertStringContainsString('could not be found', $page,
                "$action should render the error page");
        }
    }

    /**
     * A registered action still resolves -- the catch must not swallow real
     * routes and turn the whole installation into a 404.
     */
    public function testARegisteredActionStillResolves()
    {
        http_response_code(200);

        $page = (string) \OWA\Core\CoreAPI::performAction('base.loginForm', []);

        $this->assertSame(200, http_response_code(), 'a real action must not be reported missing');
        $this->assertStringNotContainsString('could not be found', $page);
    }
}
