<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Spam\Rules\UaRobotToken;
use OWA\Module\Base\Classes\Spam\Rules\UaParserSpider;

/**
 * The user_agent rule set: what Browscap::robotCheck() decided, as two rules.
 *
 * Moved from BrowscapRobotDetectionTest and BotDetectionTest, which asserted
 * the same things of isRobot() and robotRegexCheck(). Those are gone; the
 * token list is UA_ROBOT_TOKEN and the parser's Spider is UA_PARSER_SPIDER,
 * and `normal` blocks either one at the default threshold.
 */
final class SpamUserAgentRulesTest extends TestCase
{
    private const CHROME_148 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

    private function fires(string $rule, string $ua): bool
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->set('HTTP_USER_AGENT', $ua);

        return (new $rule())->test($event, \OWA\Module\Base\Classes\Spam\Score::headers($event));
    }

    private function eitherFires(string $ua): bool
    {
        return $this->fires(UaRobotToken::class, $ua) || $this->fires(UaParserSpider::class, $ua);
    }

    public function testTheTwoRulesAreTheUserAgentSetAtFivePoints(): void
    {
        foreach ([UaRobotToken::class => 'UA_ROBOT_TOKEN', UaParserSpider::class => 'UA_PARSER_SPIDER'] as $class => $name) {
            $rule = new $class();
            $this->assertSame($name, $rule->name());
            $this->assertSame('user_agent', $rule->set());
            $this->assertSame(5.0, $rule->points());
        }
    }

    public function testGooglebotHitsTheTokenList(): void
    {
        $this->assertTrue($this->fires(UaRobotToken::class,
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $this->assertSame('bot', UaRobotToken::matchingToken(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
    }

    /**
     * A token at position 0. stripos() answers 0 there, which a truthy check
     * read as no match: curl/, Wget/ and Java/ all passed as human once.
     */
    public function testAUserAgentBeginningWithAToken(): void
    {
        foreach ([
            'curl/7.68.0', 'Wget/1.21.3', 'Java/17.0.1', 'php-httpclient/2.0',
            'perl-libwww/6.0', 'lwp-request/6.0', 'libwww-perl/6.67', 'Bot/1.0 (+http://example.com)',
        ] as $ua) {
            $this->assertTrue($this->fires(UaRobotToken::class, $ua), "a token at the start went undetected: $ua");
        }
    }

    public function testATokenMidString(): void
    {
        foreach ([
            'Mozilla/5.0 (compatible; YandexBot/3.0)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'python-requests/2.31.0 curl',
        ] as $ua) {
            $this->assertTrue($this->fires(UaRobotToken::class, $ua), "a token mid-string went undetected: $ua");
        }
    }

    public function testGenericHttpClientsAndDrivers(): void
    {
        foreach ([
            'Go-http-client/2.0', 'python-requests/2.31.0',
            'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)', 'okhttp/4.9.0', 'PostmanRuntime/7.35.0',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36',
        ] as $ua) {
            $this->assertTrue($this->fires(UaRobotToken::class, $ua), "$ua must be detected");
        }
    }

    /** WhatsApp's fetcher carries no token; the parser calls it a Spider. */
    public function testTheParserCatchesWhatTheTokenListCannot(): void
    {
        $this->assertFalse($this->fires(UaRobotToken::class, 'WhatsApp/2.23.20.0 A'));
        $this->assertTrue($this->fires(UaParserSpider::class, 'WhatsApp/2.23.20.0 A'));
    }

    /**
     * Bytespider presents as Android Chrome, which the bundled parser data
     * calls a phone; the list sees "spider". The parser's answer is not
     * asserted: a newer regexes file in owa-data may know it.
     */
    public function testTheTokenListCatchesBytespider(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 6.0.1) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Version/4.0 Chrome/74 Mobile Safari/537.36 (compatible; Bytespider)';

        $this->assertTrue($this->fires(UaRobotToken::class, $ua));
    }

    /** A false positive is a page view not stored, so ordinary browsers meet neither rule. */
    public function testOrdinaryBrowsersMeetNeither(): void
    {
        foreach ([
            self::CHROME_148,
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
        ] as $ua) {
            $this->assertFalse($this->eitherFires($ua), 'a real browser met a user_agent rule: ' . substr($ua, 0, 60));
        }
    }

    /**
     * A driven browser reports Chrome's own agent, so no user-agent rule can
     * see it; the request_headers set is what tests the claim.
     */
    public function testADrivenBrowserIsInvisibleToTheUserAgent(): void
    {
        $this->assertFalse($this->eitherFires(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
    }

    public function testAnEmptyUserAgentMeetsNeither(): void
    {
        $this->assertFalse($this->eitherFires(''));
        $this->assertNull(UaRobotToken::matchingToken(''));
    }

    /** The robot methods are gone from Browscap; the parser's other readings stay. */
    public function testBrowscapNoLongerDecidesRobots(): void
    {
        foreach (['robotCheck', 'isRobot', 'robotRegexCheck', 'parserSaysSpider'] as $method) {
            $this->assertFalse(method_exists(\OWA\Module\Base\Classes\Browscap::class, $method), "Browscap::$method() is still there");
        }

        $this->assertSame('Chrome', (new \OWA\Module\Base\Classes\Browscap(self::CHROME_148))->getUaFamily());
    }
}
