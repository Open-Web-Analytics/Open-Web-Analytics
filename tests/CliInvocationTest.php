<?php

use PHPUnit\Framework\TestCase;

/**
 * cli.php must run from a shell and refuse a web request.
 *
 * It is a front script that executes whatever command it is given, so the
 * question "was this started by a person at a shell" is the only thing standing
 * between the command set and whoever can reach the file. A deployment is
 * expected to keep it out of the docroot as well, but that is a second line and
 * this is the first.
 *
 * The detection cannot simply be the SAPI name: a host with no `php` binary
 * runs scripts as `php-cgi script.php args`, and refusing every CGI SAPI would
 * refuse those installations outright. The fallback signal for that case used
 * to be argc, which does not mean what it appears to -- see CliInvocation.
 *
 * WHAT MAKES THESE TESTS DISCRIMINATING
 * The interesting case is a CGI SAPI with argc populated, which is both what a
 * legitimate php-cgi invocation looks like and what a served request can look
 * like. Every test below therefore holds argc populated and varies only whether
 * a request environment is present. A rule that ignored that distinction would
 * fail here rather than passing on the easy cases.
 */
final class CliInvocationTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once( dirname( __DIR__ ) . '/Core/CliInvocation.php' );
    }

    public function testShellRunIsAccepted() {

        $this->assertTrue(
            \OWA\Core\CliInvocation::detect( 'cli', [ 'argc' => 2 ] ),
            'an ordinary command line run must be allowed'
        );
    }

    /**
     * The php-cgi-as-interpreter case the argc fallback exists to serve. If
     * this breaks, installations without a php binary lose the CLI entirely.
     */
    public function testCgiInterpreterRunFromAShellIsAccepted() {

        $this->assertTrue(
            \OWA\Core\CliInvocation::detect( 'cgi-fcgi', [ 'argc' => 3 ] ),
            'php-cgi used as a shell interpreter must still be allowed'
        );
    }

    /**
     * The case the SAPI test alone does not catch and argc alone gets wrong:
     * a request being served, with argc populated anyway.
     */
    public function testServedRequestIsRefusedEvenWithArgcPopulated() {

        $refused = [
            'cgi-fcgi'  => [ 'argc' => 1, 'REQUEST_METHOD' => 'GET' ],
            'fpm-fcgi'  => [ 'argc' => 2, 'REQUEST_METHOD' => 'POST' ],
            'apache2handler' => [ 'argc' => 9, 'REQUEST_METHOD' => 'HEAD' ],
        ];

        foreach ( $refused as $sapi => $server ) {

            $this->assertFalse(
                \OWA\Core\CliInvocation::detect( $sapi, $server ),
                sprintf( 'a request served by %s must not count as a CLI run', $sapi )
            );
        }
    }

    /**
     * The CLI SAPI copies the surrounding environment into $_SERVER, so a shell
     * that exports REQUEST_METHOD would otherwise lock the operator out of
     * their own command line. The SAPI test is checked first for this reason.
     */
    public function testRealCliIsNotRefusedByAnInheritedRequestMethod() {

        $this->assertTrue(
            \OWA\Core\CliInvocation::detect( 'cli', [ 'argc' => 2, 'REQUEST_METHOD' => 'GET' ] ),
            'an exported REQUEST_METHOD must not disable the command line'
        );
    }

    public function testMissingOrUnusableArgcIsRefused() {

        $this->assertFalse(
            \OWA\Core\CliInvocation::detect( 'cgi-fcgi', [] ),
            'no argc and no shell SAPI is not a CLI run'
        );

        $this->assertFalse(
            \OWA\Core\CliInvocation::detect( 'cgi-fcgi', [ 'argc' => 0 ] ),
            'a zero argument count is not a CLI run'
        );

        $this->assertFalse(
            \OWA\Core\CliInvocation::detect( 'cgi-fcgi', [ 'argc' => 'not-a-number' ] ),
            'a non-numeric argc is not a CLI run'
        );
    }

    /**
     * The rule above, exercised through the real front script under a real
     * non-CLI SAPI rather than by calling the predicate directly.
     *
     * This is the test that would have caught the original: it needs no
     * agreement about what the rule should be, only php-cgi and a request
     * environment. Skips where php-cgi is not installed -- it is a separate
     * package from the CLI binary and CI does not install it -- which is why
     * the cases above exist as well rather than instead.
     */
    public function testFrontScriptRefusesARequestUnderACgiSapi() {

        $php_cgi = trim( (string) @shell_exec( 'command -v php-cgi 2>/dev/null' ) );

        if ( ! $php_cgi ) {

            $this->markTestSkipped( 'php-cgi is not installed' );
        }

        $cli = dirname( __DIR__ ) . '/cli.php';

        // register_argc_argv is what fills argc in for a served request, so it
        // is turned on deliberately: this reproduces the installation whose ini
        // makes argc an unreliable signal, not a hypothetical one.
        $cmd = sprintf(
            'REQUEST_METHOD=GET SERVER_PROTOCOL=HTTP/1.1 QUERY_STRING=%s REDIRECT_STATUS=1 SCRIPT_FILENAME=%s '
          . '%s -d register_argc_argv=On %s 2>&1',
            escapeshellarg( 'cmd=flush-cache' ),
            escapeshellarg( $cli ),
            escapeshellarg( $php_cgi ),
            escapeshellarg( $cli )
        );

        $output = (string) shell_exec( $cmd );

        $this->assertStringContainsString(
            '404',
            $output,
            'a request under a CGI SAPI must be turned away as a missing file'
        );

        // The failure this replaces did not stop here: it reached argument
        // parsing, which means the front script had already loaded OWA for a
        // caller arriving over HTTP. Naming the symptom keeps the test honest
        // if the 404 above is ever produced for some other reason.
        $this->assertStringNotContainsString(
            'parseCliArgs',
            $output,
            'the request must be refused before the script parses arguments'
        );

        $this->assertStringNotContainsString(
            'Arguments required',
            $output,
            'the request must be refused before the script looks for a command'
        );
    }
}
