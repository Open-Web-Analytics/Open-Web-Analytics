<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The user-agent parse is memoised PER AGENT, not per process.
 *
 * It used to keep the first parse a process made and ignore every $ua handed to
 * it afterwards. One request parses once either way, so nothing showed -- but a
 * queue drain walks many events in one process, and every one of them after the
 * first was versioned by whichever agent the drain happened to see first.
 * Everything read off that parse went with it: browser, browser_version, os,
 * os_version, device_type, device_brand, device_model.
 *
 * Found while making those five columns into properties with callbacks of their
 * own: five callbacks each asking for the parse is only cheap if the memo keys on
 * what they ask for.
 */
final class BrowscapMemoTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                         . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) '
                         . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->setBrowscap( null );
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->setBrowscap( null );
    }

    /** Two agents in one process are two parses, not one. */
    public function testASecondAgentIsNotGivenTheFirstsParse(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $desktop = $service->getBrowscap( self::CHROME );
        $mobile  = $service->getBrowscap( self::IPHONE );

        $this->assertNotSame( $desktop, $mobile,
            'The second agent was handed the first agent\'s parse. In a queue drain that '
            . 'versions every event after the first by the wrong browser.' );

        $this->assertSame( 'Mac OS X', $desktop->getOsFamily() );
        $this->assertSame( 'iOS', $mobile->getOsFamily() );
    }

    /** ...and asking twice for one agent still parses once. */
    public function testOneAgentIsParsedOnce(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $this->assertSame(
            $service->getBrowscap( self::CHROME ),
            $service->getBrowscap( self::CHROME ),
            'the memo is what makes five properties asking for one parse cheap' );
    }

    /**
     * An injected parser outranks the memo, which is how a test hands over a
     * stub -- and null clears the memo rather than injecting nothing.
     */
    public function testAnInjectedParserWins(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $real = $service->getBrowscap( self::CHROME );

        $stub = new class {
            public function getOsFamily() { return 'StubOS'; }
        };

        $service->setBrowscap( $stub );

        $this->assertSame( $stub, $service->getBrowscap( self::IPHONE ) );

        $service->setBrowscap( null );

        $this->assertNotSame( $stub, $service->getBrowscap( self::CHROME ) );
        $this->assertNotSame( $real, $service->getBrowscap( self::CHROME ),
            'null clears the memo, so the next ask is a fresh parse' );
    }
}
