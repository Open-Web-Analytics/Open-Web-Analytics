<?php

use PHPUnit\Framework\TestCase;

/**
 * What OWA can and cannot tell about a crawler.
 *
 * Detection matters more than it looks, because a detected robot is DISCARDED
 * rather than flagged -- CoreAPI aborts the event before it is logged. So a
 * miss inflates the numbers and a false positive silently destroys a real page
 * view. Both directions cost something, which is why the signals below are
 * specific rather than clever.
 *
 * THE PART THAT IS NOT SOLVABLE FROM THE USER AGENT
 * A JavaScript tracker only ever sees a crawler that runs JavaScript, and those
 * present as ordinary Chrome because they ARE Chrome under automation. Measured
 * on this installation: a single such crawler made 365 requests over two days
 * and was recorded as a person, because its user agent was a valid desktop
 * Chrome string. No parser and no data file identifies that. The browser can,
 * and does, through navigator.webdriver -- which is why the tracker forwards it.
 */
final class BotDetectionTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function isRobot( $ua ) {

        $b = new \OWA\Module\Base\Classes\Browscap( $ua );

        return $b->isRobot();
    }

    /**
     * Self-identifying crawlers. These worked before and must keep working.
     */
    public function testCrawlersThatNameThemselvesAreDetected(): void {

        foreach ( [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'curl/8.4.0',
        ] as $ua ) {

            $this->assertTrue( $this->isRobot( $ua ), sprintf( '%s must be detected', $ua ) );
        }
    }

    /**
     * Generic HTTP clients. None of these carry a token the original list
     * matched, and none of them are browsers.
     */
    public function testGenericHttpClientsAreDetected(): void {

        foreach ( [
            'Go-http-client/2.0',
            'python-requests/2.31.0',
            'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)',
            'okhttp/4.9.0',
            'PostmanRuntime/7.35.0',
        ] as $ua ) {

            $this->assertTrue( $this->isRobot( $ua ), sprintf( '%s must be detected', $ua ) );
        }
    }

    /**
     * The parser's opinion, for crawlers no token list would think to include.
     * WhatsApp's fetcher is the example: it carries nothing robot-like at all.
     */
    public function testTheParserCatchesCrawlersTheTokenListDoesNot(): void {

        $this->assertTrue( $this->isRobot( 'WhatsApp/2.23.20.0 A' ),
            'the user-agent parser classifies this as a Spider; the token list cannot see it' );
    }

    /**
     * And the reverse, which is why the parser is a second opinion rather than
     * a replacement: Bytespider disguises itself as Android Chrome, and the
     * parser calls it a phone.
     */
    public function testTheTokenListCatchesCrawlersTheParserDoesNot(): void {

        $ua = 'Mozilla/5.0 (Linux; Android 6.0.1) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Version/4.0 Chrome/74 Mobile Safari/537.36 (compatible; Bytespider)';

        $this->assertTrue( $this->isRobot( $ua ),
            'the token list sees "spider"; the parser classifies this as a phone' );
    }

    /**
     * The cost of a false positive is a destroyed page view, so ordinary
     * browsers must pass. This is the assertion that keeps the token list
     * honest as it grows.
     */
    public function testOrdinaryBrowsersAreNotDetected(): void {

        foreach ( [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
        ] as $ua ) {

            $this->assertFalse( $this->isRobot( $ua ),
                sprintf( 'a real browser must not be discarded: %s', substr( $ua, 0, 60 ) ) );
        }
    }

    /**
     * An automation driver that does not hide itself.
     */
    public function testHeadlessChromeIsDetectedByItsUserAgent(): void {

        $this->assertTrue( $this->isRobot(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'
        ) );
    }

    /**
     * The case the whole of tier 2 exists for: a driven browser reporting a
     * perfectly ordinary user agent. The server cannot tell from the string --
     * asserted here, so the test fails if someone later claims it can -- and
     * has to be told by the client.
     */
    public function testADrivenBrowserIsInvisibleToTheUserAgentAndVisibleToTheClientSignal(): void {

        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->assertFalse( $this->isRobot( $ua ),
            'this is a valid Chrome string; nothing on the server can call it a robot' );

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'HTTP_USER_AGENT', $ua );
        $event->set( 'is_automated', 1 );

        $this->assertTrue(
            (bool) \OWA\Module\Base\Classes\TrackingEventHelpers::isRobot( false, $event ),
            'the browser reported it was under automation and that must be believed'
        );
    }

    public function testAnEventWithoutTheSignalIsUnaffected(): void {

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'HTTP_USER_AGENT',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120' );

        $this->assertFalse(
            (bool) \OWA\Module\Base\Classes\TrackingEventHelpers::isRobot( false, $event ),
            'an ordinary browser that sends no signal must still be a person'
        );
    }
}
