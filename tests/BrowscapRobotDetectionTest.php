<?php

use PHPUnit\Framework\TestCase;

/**
 * A robot token at position 0 of the user agent must still count as a robot.
 *
 * Browscap::robotRegexCheck() did `$match = stripos($ua, $robot); if ($match)`,
 * and stripos() returns int 0 -- falsy -- for a match at the start of the
 * string. So any user agent that *begins* with a robot token passed as human:
 * `curl/7.68.0`, `Wget/1.21.3` and `Java/17.0.1` were all undetected, while
 * the very same tokens were caught mid-string. The affected tokens (curl,
 * wget, java, php, perl, lwp, libwww) are exactly the ones that convention
 * puts at the start of a UA.
 *
 * This matters more than its size suggests because reclassification is
 * collection-time only: a bot logged as human today is human in the data
 * forever.
 */
final class BrowscapRobotDetectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function browscap(string $ua): \OWA\Module\Base\Classes\Browscap
    {
        return new \OWA\Module\Base\Classes\Browscap($ua);
    }

    /**
     * The bug: tokens at position 0. Every one of these passed as human.
     */
    public function testUserAgentBeginningWithRobotTokenIsARobot(): void
    {
        $leading = [
            'curl/7.68.0',
            'Wget/1.21.3',
            'Java/17.0.1',
            'php-httpclient/2.0',
            'perl-libwww/6.0',
            'lwp-request/6.0',
            'libwww-perl/6.67',
            'Bot/1.0 (+http://example.com)',
        ];

        foreach ($leading as $ua) {
            $this->assertTrue(
                (bool) $this->browscap($ua)->isRobot(),
                "UA beginning with a robot token passed as human: $ua"
            );
        }
    }

    /**
     * Mid-string detection worked before the fix and must keep working.
     */
    public function testRobotTokenMidStringIsStillDetected(): void
    {
        $midString = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; YandexBot/3.0)',
            'python-requests/2.31.0 curl',
        ];

        foreach ($midString as $ua) {
            $this->assertTrue(
                (bool) $this->browscap($ua)->isRobot(),
                "Robot token mid-string went undetected: $ua"
            );
        }
    }

    /**
     * Real browsers stay human -- the fix must not widen the match.
     */
    public function testOrdinaryBrowserUserAgentsAreNotRobots(): void
    {
        $browsers = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
        ];

        foreach ($browsers as $ua) {
            $this->assertFalse(
                (bool) $this->browscap($ua)->isRobot(),
                "Ordinary browser misclassified as robot: $ua"
            );
        }
    }

    /**
     * The return value is a clean boolean on both paths. Before the fix it was
     * false | int -- a stripos position -- which happened to work for truthy
     * checks at nonzero positions and silently failed at zero.
     */
    public function testRobotCheckReturnsABoolean(): void
    {
        $this->assertIsBool($this->browscap('curl/7.68.0')->robotRegexCheck());
        $this->assertIsBool($this->browscap('Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0')->robotRegexCheck());
    }

    /**
     * An empty user agent is not a robot -- and must not warn or throw.
     */
    public function testEmptyUserAgentIsNotARobot(): void
    {
        $this->assertFalse((bool) $this->browscap('')->isRobot());
    }
}
