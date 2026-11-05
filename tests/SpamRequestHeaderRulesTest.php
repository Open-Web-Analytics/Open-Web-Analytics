<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Spam\Rules\NoFetchMetadata;
use OWA\Module\Base\Classes\Spam\Rules\NoClientHints;
use OWA\Module\Base\Classes\Spam\Rules\ChPlatformMismatch;
use OWA\Module\Base\Classes\Spam\Rules\ChInvalidValue;
use OWA\Module\Base\Classes\Spam\Rules\ChMobileMismatch;
use OWA\Module\Base\Classes\Spam\Rules\UaMalformed;
use OWA\Module\Base\Classes\Spam\Rules\AcceptEncodingNotBrowser;
use OWA\Module\Base\Classes\Spam\Rules\NoAcceptLanguage;
use OWA\Module\Base\Classes\Spam\Score;

/**
 * The request_headers rule set: does a request look like it came from the
 * browser its user agent names.
 *
 * Each rule is handed the headers directly, as Score::headers() would read them
 * off the event; a header left out is one the request did not carry.
 */
final class SpamRequestHeaderRulesTest extends TestCase
{
    const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.0.0 Safari/537.36';
    const MAC_CHROME     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const LINUX_CHROME   = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const ANDROID_PHONE  = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    const ANDROID_TABLET = 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const CHROME_OS      = 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const IPHONE_CRIOS   = 'Mozilla/5.0 (iPhone; CPU iPhone OS %s like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/112.0.5615.46 Mobile/15E148 Safari/604.1';
    const IPHONE_SAFARI  = 'Mozilla/5.0 (iPhone; CPU iPhone OS %s like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/%s Mobile/15E148 Safari/604.1';
    const MAC_SAFARI     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/%s Safari/605.1.15';
    const FIREFOX        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:%d.0) Gecko/20100101 Firefox/%d.0';
    const EDGE           = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
    const OPERA          = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0';
    const SAMSUNG        = 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36';
    const WEBVIEW        = 'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230805.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.230 Mobile Safari/537.36';

    /** A real Chrome 148 beacon's headers, from app's WAF log (as the brief gave them). */
    const CHROME_148 = array(
        'sec-ch-ua-platform' => '"Windows"',
        'user-agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
        'sec-ch-ua'          => '"Not/A)Brand";v="99", "Chromium";v="148"',
        'sec-ch-ua-mobile'   => '?0',
        'sec-fetch-site'     => 'same-site',
        'sec-fetch-mode'     => 'no-cors',
        'sec-fetch-dest'     => 'empty',
        'accept-encoding'    => 'gzip, deflate, br, zstd',
    );

    /** The 4 November attack's beacon headers. */
    const ATTACK = array(
        'user-agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36',
        'accept-language' => 'en-US,en;q=0.9',
        'referer'         => 'https://www.openwebanalytics.com/',
        'accept-encoding' => 'gzip',
    );

    private function fires(string $rule, array $headers): bool
    {
        return (new $rule())->test(new \OWA\Module\Base\Classes\Event(), $headers + array('user-agent' => ''));
    }

    private static function chrome(int $major): string
    {
        return sprintf(self::WINDOWS_CHROME, $major);
    }

    /** An event carrying these headers the way the edge stamps them, on a page of the site. */
    private static function beacon(array $headers, string $page = 'https://www.openwebanalytics.com/'): object
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->set('page_location', $page);

        $properties = array(
            'user-agent' => 'HTTP_USER_AGENT', 'sec-fetch-mode' => 'request_sec_fetch_mode',
            'sec-fetch-site' => 'request_sec_fetch_site', 'sec-fetch-dest' => 'request_sec_fetch_dest',
            'sec-ch-ua' => 'request_sec_ch_ua', 'sec-ch-ua-platform' => 'request_sec_ch_ua_platform',
            'sec-ch-ua-mobile' => 'request_sec_ch_ua_mobile', 'accept-encoding' => 'request_accept_encoding',
            'accept-language' => 'request_accept_language', 'referer' => 'request_referer',
        );

        foreach ($headers as $header => $value) {
            $event->set($properties[$header], $value);
        }

        return $event;
    }

    // ---- NO_FETCH_METADATA ----------------------------------------------

    public function testFetchMetadataVersionBoundaries(): void
    {
        foreach ([
            'Chrome 75'   => [self::chrome(75), false],
            'Chrome 76'   => [self::chrome(76), true],
            'Firefox 89'  => [sprintf(self::FIREFOX, 89, 89), false],
            'Firefox 90'  => [sprintf(self::FIREFOX, 90, 90), true],
            'Safari 16.3' => [sprintf(self::MAC_SAFARI, '16.3'), false],
            'Safari 16.4' => [sprintf(self::MAC_SAFARI, '16.4'), true],
            'iOS 16.3'    => [sprintf(self::IPHONE_SAFARI, '16_3', '16.3'), false],
            'iOS 16.4'    => [sprintf(self::IPHONE_SAFARI, '16_4', '16.4'), true],
            'CriOS, iOS 16.3' => [sprintf(self::IPHONE_CRIOS, '16_3'), false],
            'CriOS, iOS 16.4' => [sprintf(self::IPHONE_CRIOS, '16_4'), true],
            'iOS 15.6'    => [sprintf(self::IPHONE_SAFARI, '15_6', '15.6'), false],
            'Opera on Chromium 120' => [self::OPERA, true],
            'Samsung on Chromium 115' => [self::SAMSUNG, true],
        ] as $label => [$ua, $fires]) {
            $this->assertSame($fires, $this->fires(NoFetchMetadata::class, ['user-agent' => $ua]), $label);
        }
    }

    public function testFetchMetadataPresentNeverFires(): void
    {
        foreach ([self::chrome(133), sprintf(self::FIREFOX, 124, 124), sprintf(self::MAC_SAFARI, '17.4')] as $ua) {
            $this->assertFalse($this->fires(NoFetchMetadata::class, ['user-agent' => $ua, 'sec-fetch-mode' => 'no-cors']));
        }
    }

    public function testGooglebotClaimsNoFetchMetadata(): void
    {
        $this->assertFalse($this->fires(NoFetchMetadata::class,
            ['user-agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']));
        $this->assertFalse($this->fires(NoFetchMetadata::class, ['user-agent' => '']));
    }

    // ---- NO_CLIENT_HINTS --------------------------------------------------

    public function testClientHintsVersionBoundaryAndBrowsers(): void
    {
        foreach ([
            'Chrome 88'      => [self::chrome(88), false],
            'Chrome 89'      => [self::chrome(89), true],
            'Edge'           => [self::EDGE, true],
            'Opera'          => [self::OPERA, true],
            'Samsung'        => [self::SAMSUNG, true],
            'Android WebView' => [self::WEBVIEW, true],
            'Chrome Mobile'  => [self::ANDROID_PHONE, true],
        ] as $label => [$ua, $fires]) {
            $this->assertSame($fires, $this->fires(NoClientHints::class, ['user-agent' => $ua]), $label);
        }
    }

    public function testCriosFirefoxAndSafariNeverFireClientHints(): void
    {
        foreach ([
            sprintf(self::IPHONE_CRIOS, '17_0'),
            sprintf(self::FIREFOX, 124, 124),
            sprintf(self::MAC_SAFARI, '17.4'),
            sprintf(self::IPHONE_SAFARI, '17_0', '17.0'),
        ] as $ua) {
            $this->assertFalse($this->fires(NoClientHints::class, ['user-agent' => $ua]), $ua);
        }
    }

    public function testClientHintsPresentNeverFires(): void
    {
        $this->assertFalse($this->fires(NoClientHints::class,
            ['user-agent' => self::chrome(133), 'sec-ch-ua' => '"Chromium";v="133"']));
    }

    // ---- CH_PLATFORM_MISMATCH and CH_INVALID_VALUE -------------------------

    /** @dataProvider platforms */
    public function testEveryPlatformAgainstMatchingAndMismatchingAgents(string $platform, string $ua, bool $fires): void
    {
        $this->assertSame($fires, $this->fires(ChPlatformMismatch::class,
            ['user-agent' => $ua, 'sec-ch-ua-platform' => '"' . $platform . '"']));

        $this->assertFalse($this->fires(ChInvalidValue::class,
            ['user-agent' => $ua, 'sec-ch-ua-platform' => '"' . $platform . '"']), "$platform is a value Chromium sends");
    }

    public static function platforms(): array
    {
        return [
            'Windows, Windows'         => ['Windows', sprintf(self::WINDOWS_CHROME, 120), false],
            'Windows, Mac'             => ['Windows', self::MAC_CHROME, true],
            'Windows, Android'         => ['Windows', self::ANDROID_PHONE, true],
            'Windows, iOS'             => ['Windows', sprintf(self::IPHONE_CRIOS, '17_0'), true],
            'macOS, Mac'               => ['macOS', self::MAC_CHROME, false],
            'macOS, Windows'           => ['macOS', sprintf(self::WINDOWS_CHROME, 120), true],
            'Linux, Linux'             => ['Linux', self::LINUX_CHROME, false],
            'Linux, Mac'               => ['Linux', self::MAC_CHROME, true],
            'Linux, Windows'           => ['Linux', sprintf(self::WINDOWS_CHROME, 120), true],
            'Linux, Android'           => ['Linux', self::ANDROID_PHONE, false],
            'Android, Android phone'   => ['Android', self::ANDROID_PHONE, false],
            'Android, Android tablet'  => ['Android', self::ANDROID_TABLET, false],
            'Android, desktop-site Linux' => ['Android', self::LINUX_CHROME, false],
            'Android, Windows'         => ['Android', sprintf(self::WINDOWS_CHROME, 120), true],
            'Chrome OS, CrOS'          => ['Chrome OS', self::CHROME_OS, false],
            'Chrome OS, Windows'       => ['Chrome OS', sprintf(self::WINDOWS_CHROME, 120), true],
            'Chromium OS, CrOS'        => ['Chromium OS', self::CHROME_OS, false],
            'Chromium OS, Mac'         => ['Chromium OS', self::MAC_CHROME, true],
            'iOS, iPhone'              => ['iOS', sprintf(self::IPHONE_CRIOS, '17_0'), false],
            'iOS, Windows'             => ['iOS', sprintf(self::WINDOWS_CHROME, 120), true],
            'Unknown, Mac'             => ['Unknown', self::MAC_CHROME, false],
            'Unknown, Windows'         => ['Unknown', sprintf(self::WINDOWS_CHROME, 120), false],
        ];
    }

    /** The Baidu beacons: agent `android`, platform "Windows", mobile ?1. */
    public function testABareAndroidAgentClaimingWindows(): void
    {
        $headers = ['user-agent' => 'android', 'sec-ch-ua-platform' => '"Windows"', 'sec-ch-ua-mobile' => '?1'];

        $this->assertTrue($this->fires(ChPlatformMismatch::class, $headers));
        $this->assertTrue($this->fires(ChMobileMismatch::class, $headers));
        $this->assertTrue($this->fires(UaMalformed::class, $headers));
    }

    public function testNoPlatformHeaderNeverFires(): void
    {
        $this->assertFalse($this->fires(ChPlatformMismatch::class, ['user-agent' => self::MAC_CHROME]));
        $this->assertFalse($this->fires(ChInvalidValue::class, ['user-agent' => self::MAC_CHROME]));
    }

    public function testAValueChromiumNeverSends(): void
    {
        foreach ([
            ['sec-ch-ua-platform' => '"Mac OS X"'],
            ['sec-ch-ua-platform' => '"windows"'],
            ['sec-ch-ua-mobile' => '?2'],
            ['sec-ch-ua-mobile' => '1'],
            ['sec-ch-ua-mobile' => 'true'],
        ] as $headers) {
            $this->assertTrue($this->fires(ChInvalidValue::class, $headers + ['user-agent' => self::MAC_CHROME]),
                var_export($headers, true));
        }

        foreach (['?0', '?1'] as $mobile) {
            $this->assertFalse($this->fires(ChInvalidValue::class,
                ['user-agent' => self::MAC_CHROME, 'sec-ch-ua-mobile' => $mobile]));
        }

        // Not a platform it knows, so not a mismatch: that is this rule's.
        $this->assertFalse($this->fires(ChPlatformMismatch::class,
            ['user-agent' => self::MAC_CHROME, 'sec-ch-ua-platform' => '"Mac OS X"']));
    }

    // ---- CH_MOBILE_MISMATCH -----------------------------------------------

    public function testMobileHintAgainstTheMobileToken(): void
    {
        foreach ([
            'phone, ?1'   => [self::ANDROID_PHONE, '?1', false],
            'phone, ?0'   => [self::ANDROID_PHONE, '?0', true],
            'tablet, ?0'  => [self::ANDROID_TABLET, '?0', false],
            'tablet, ?1'  => [self::ANDROID_TABLET, '?1', true],
            'desktop, ?0' => [self::MAC_CHROME, '?0', false],
            'desktop, ?1' => [self::MAC_CHROME, '?1', true],
            'invalid'     => [self::MAC_CHROME, '?2', false],
            'absent'      => [self::MAC_CHROME, '', false],
        ] as $label => [$ua, $mobile, $fires]) {
            $this->assertSame($fires, $this->fires(ChMobileMismatch::class,
                ['user-agent' => $ua, 'sec-ch-ua-mobile' => $mobile]), $label);
        }
    }

    // ---- UA_MALFORMED -----------------------------------------------------

    public function testMalformedAgents(): void
    {
        foreach ([
            'android',
            '"' . self::MAC_CHROME,
            self::MAC_CHROME . '"',
            "'" . self::MAC_CHROME . "'",
            // A browser's tokens without the prefix every browser sends.
            'Chrome/120.0.0.0 Safari/537.36',
            'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ] as $ua) {
            $this->assertTrue($this->fires(UaMalformed::class, ['user-agent' => $ua]), $ua);
        }
    }

    public function testHonestAgentsAreNotMalformed(): void
    {
        foreach ([
            'MyApp/1.2',
            'curl/8.1',
            'Go-http-client/1.1',
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/120.0.0.0 Safari/537.36',
            self::CHROME_148['user-agent'],
            sprintf(self::FIREFOX, 124, 124),
            sprintf(self::IPHONE_SAFARI, '17_0', '17.0'),
            '',
        ] as $ua) {
            $this->assertFalse($this->fires(UaMalformed::class, ['user-agent' => $ua]), $ua);
        }
    }

    // ---- ACCEPT_ENCODING_NOT_BROWSER and NO_ACCEPT_LANGUAGE -----------------

    public function testEachAcceptEncodingForm(): void
    {
        foreach ([
            'missing'           => ['', true],
            'gzip'              => ['gzip', true],
            'GZIP'              => ['GZIP', true],
            'no spaces'         => ['gzip,deflate,br', true],
            'one space missing' => ['gzip, deflate,br', true],
            'Chrome and Safari' => ['gzip, deflate, br', false],
            'with zstd'         => ['gzip, deflate, br, zstd', false],
        ] as $label => [$value, $fires]) {
            $this->assertSame($fires, $this->fires(AcceptEncodingNotBrowser::class,
                ['user-agent' => self::chrome(148), 'accept-encoding' => $value]), $label);
        }
    }

    public function testAcceptEncodingIsOnlyAskedOfABrowser(): void
    {
        foreach (['MyApp/1.2', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ''] as $ua) {
            $this->assertFalse($this->fires(AcceptEncodingNotBrowser::class, ['user-agent' => $ua, 'accept-encoding' => 'gzip']), $ua);
        }

        $this->assertTrue($this->fires(AcceptEncodingNotBrowser::class,
            ['user-agent' => sprintf(self::FIREFOX, 124, 124), 'accept-encoding' => 'gzip']));
        $this->assertTrue($this->fires(AcceptEncodingNotBrowser::class,
            ['user-agent' => sprintf(self::MAC_SAFARI, '17.4'), 'accept-encoding' => 'gzip']));
    }

    public function testNoAcceptLanguage(): void
    {
        $this->assertTrue($this->fires(NoAcceptLanguage::class, ['user-agent' => self::chrome(148)]));
        $this->assertFalse($this->fires(NoAcceptLanguage::class,
            ['user-agent' => self::chrome(148), 'accept-language' => 'en-US,en;q=0.9']));
        $this->assertFalse($this->fires(NoAcceptLanguage::class, ['user-agent' => 'MyApp/1.2']));
        $this->assertFalse($this->fires(NoAcceptLanguage::class,
            ['user-agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']));
    }

    // ---- the set as a whole -----------------------------------------------

    public function testTheSetsRulesAndPoints(): void
    {
        $expected = [
            'NO_FETCH_METADATA' => 5.0, 'NO_CLIENT_HINTS' => 2.5, 'CH_PLATFORM_MISMATCH' => 3.0,
            'CH_INVALID_VALUE' => 3.0, 'CH_MOBILE_MISMATCH' => 2.0, 'UA_MALFORMED' => 3.0,
            'ACCEPT_ENCODING_NOT_BROWSER' => 2.0, 'NO_ACCEPT_LANGUAGE' => 1.0,
        ];

        $found = [];

        foreach (\OWA\Module\Base\Classes\Spam\RuleSets::SETS['request_headers'] as $class) {
            $rule = new $class();
            $this->assertSame('request_headers', $rule->set());
            $found[$rule->name()] = $rule->points();
        }

        $this->assertSame($expected, $found);
    }

    /**
     * The real Chrome 148 beacon scores 0 under strict once it carries the
     * Accept-Language and Referer every browser beacon in the WAF log had.
     */
    public function testARealChromeBeaconScoresZero(): void
    {
        $verdict = Score::of(self::beacon(self::CHROME_148 + [
            'accept-language' => 'en-US,en;q=0.9',
            'referer'         => 'https://www.openwebanalytics.com/',
        ]), 'strict');

        $this->assertSame(0.0, $verdict['score'], $verdict['rules']);
        $this->assertSame('', $verdict['rules']);
    }

    /**
     * The headers exactly as the brief lists them have no Accept-Language and
     * no Referer, which two strict rules notice. Below the threshold.
     */
    public function testTheBriefsChromeHeaderListAsGiven(): void
    {
        $verdict = Score::of(self::beacon(self::CHROME_148), 'strict');

        $this->assertSame('NO_ACCEPT_LANGUAGE=1.0 NO_REFERER=1.0', $verdict['rules']);
        $this->assertSame(2.0, $verdict['score']);

        $this->assertSame(0.0, Score::of(self::beacon(self::CHROME_148), 'normal')['score']);
    }

    /**
     * The attack's beacon. 7.5 from the two rules the set started with, and
     * 2.0 more for Go's bare `gzip`. Nothing under normal.
     */
    public function testTheAttacksBeacon(): void
    {
        $strict = Score::of(self::beacon(self::ATTACK), 'strict');

        $this->assertSame('NO_FETCH_METADATA=5.0 NO_CLIENT_HINTS=2.5 ACCEPT_ENCODING_NOT_BROWSER=2.0', $strict['rules']);
        $this->assertSame(9.5, $strict['score']);

        $normal = Score::of(self::beacon(self::ATTACK), 'normal');

        $this->assertSame(0.0, $normal['score']);
        $this->assertSame('', $normal['rules']);
    }
}
