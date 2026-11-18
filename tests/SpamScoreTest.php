<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Spam\RuleSets;
use OWA\Module\Base\Classes\Spam\Score;

/**
 * The scorer: the sum, the spam_rules text, the level -> rule sets mapping and
 * its filter, and the threshold.
 */
final class SpamScoreTest extends TestCase
{
    /** @var array|null what the spam_rule_sets filter does while a test runs; null leaves the sets alone */
    public static $adjust = null;

    private static $registered = false;

    private $settings = [];

    protected function setUp(): void
    {
        if (!self::$registered) {
            \OWA\Core\CoreAPI::registerFilter(RuleSets::FILTER, [self::class, 'adjustSets']);
            self::$registered = true;
        }

        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach (['spam_detection', 'spam_threshold'] as $key) {
            $this->settings[$key] = $c->get('base', $key);
        }
    }

    protected function tearDown(): void
    {
        self::$adjust = null;

        foreach ($this->settings as $key => $value) {
            \OWA\Core\CoreAPI::configSingleton()->set('base', $key, $value);
        }
    }

    /** The filter listener: drop sets, or add them, as the running test asks. */
    public static function adjustSets($sets, $level)
    {
        if (self::$adjust === null) {
            return $sets;
        }

        foreach ((array) (self::$adjust['drop'] ?? []) as $set) {
            unset($sets[$set]);
        }

        foreach ((array) (self::$adjust['add'] ?? []) as $set => $classes) {
            $sets[$set] = $classes;
        }

        self::$adjust['saw_level'] = $level;

        return $sets;
    }

    /** An event that meets nothing in any shipped set. */
    private function quiet(): object
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('custom_thing');
        $event->set('HTTP_USER_AGENT', 'MyApp/1.2');
        $event->set('request_referer', 'https://example.com/');

        return $event;
    }

    public function testTheLevelsAndTheirSets(): void
    {
        $this->assertSame(['user_agent'], array_keys(RuleSets::forLevel('normal')));
        $this->assertSame(['user_agent', 'request_headers', 'page_origin', 'query_string'],
            array_keys(RuleSets::forLevel('strict')));

        $this->assertSame(array_keys(RuleSets::SETS), array_keys(RuleSets::forLevel('strict')),
            'strict runs every shipped set');
    }

    public function testAnUnknownLevelIsNormal(): void
    {
        $this->assertSame(array_keys(RuleSets::forLevel('normal')), array_keys(RuleSets::forLevel('paranoid')));

        \OWA\Core\CoreAPI::configSingleton()->set('base', 'spam_detection', 'paranoid');
        $this->assertSame('normal', RuleSets::level());

        \OWA\Core\CoreAPI::configSingleton()->set('base', 'spam_detection', 'strict');
        $this->assertSame('strict', RuleSets::level());
    }

    public function testTheDefaults(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertSame('normal', $c->default_config['base']['spam_detection']);
        $this->assertSame(5.0, $c->default_config['base']['spam_threshold']);
    }

    /** The filter can drop a set that misbehaves, and is told the level. */
    public function testTheFilterDropsASet(): void
    {
        self::$adjust = ['drop' => ['request_headers']];

        $this->assertSame(['user_agent', 'page_origin', 'query_string'], array_keys(RuleSets::forLevel('strict')));
        $this->assertSame('strict', self::$adjust['saw_level']);

        // The attack's agent and headers meet only request_headers rules.
        $event = $this->quiet();
        $event->set('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36');
        $event->set('request_accept_language', 'en-US');

        $this->assertSame(0.0, Score::of($event, 'strict')['score']);
    }

    /** ...and a module can add one; only the rules of the level's sets run. */
    public function testTheFilterAddsASetAndTheScoreIsTheSum(): void
    {
        self::$adjust = ['add' => ['test' => [
            SpamScoreTestRuleSmall::class, SpamScoreTestRuleLarge::class, SpamScoreTestRuleNegative::class,
            SpamScoreTestRuleSilent::class]]];

        $verdict = Score::of($this->quiet(), 'normal');

        $this->assertSame(['TEST_LARGE' => 4.0, 'TEST_SMALL' => 2.5, 'TEST_NEGATIVE' => -1.0], $verdict['fired']);
        $this->assertSame(5.5, $verdict['score'], 'the plain sum');
        $this->assertSame('TEST_LARGE=4.0 TEST_SMALL=2.5 TEST_NEGATIVE=-1.0', $verdict['rules']);
    }

    public function testASetThatIsNotARuleIsSkipped(): void
    {
        self::$adjust = ['add' => ['test' => [\stdClass::class, 'NoSuchRuleClass', SpamScoreTestRuleSmall::class]]];

        $this->assertSame('TEST_SMALL=2.5', Score::of($this->quiet(), 'normal')['rules']);
    }

    /** A name spam_rules or owa_spam_block cannot hold is skipped. */
    public function testARuleWithoutARuleNameIsSkipped(): void
    {
        self::$adjust = ['add' => ['test' => [
            SpamScoreTestRuleLongName::class, SpamScoreTestRuleSpacedName::class, SpamScoreTestRuleNoName::class,
            SpamScoreTestRuleAbstract::class, SpamScoreTestRuleSmall::class]]];

        $this->assertSame('TEST_SMALL=2.5', Score::of($this->quiet(), 'normal')['rules']);
    }

    /** A second rule of a name already taken is skipped: the first keeps it. */
    public function testASecondRuleOfANameIsSkipped(): void
    {
        self::$adjust = ['add' => [
            'test' => [SpamScoreTestRuleSmall::class],
            'other' => [SpamScoreTestRuleSmallAgain::class, SpamScoreTestRuleLarge::class],
        ]];

        $verdict = Score::of($this->quiet(), 'normal');

        $this->assertSame(['TEST_LARGE' => 4.0, 'TEST_SMALL' => 2.5], $verdict['fired']);
        $this->assertSame(6.5, $verdict['score']);
    }

    /** A rule that throws is not met, and the rest still score the beacon. */
    public function testARuleThatThrowsIsNotMet(): void
    {
        self::$adjust = ['add' => ['test' => [
            SpamScoreTestRuleThrows::class, SpamScoreTestRuleSmall::class]]];

        $verdict = Score::of($this->quiet(), 'normal');

        $this->assertSame(['TEST_SMALL' => 2.5], $verdict['fired']);
    }

    /** Every shipped rule's name is one a module's would have to be. */
    public function testEveryShippedRuleHasARuleName(): void
    {
        $names = [];

        foreach (RuleSets::SETS as $classes) {
            foreach ($classes as $class) {
                $this->assertMatchesRegularExpression(\OWA\Module\Base\Classes\Spam\Rule::NAME_PATTERN, $class::NAME, $class);
                $names[] = $class::NAME;
            }
        }

        $this->assertSame(count($names), count(array_unique($names)), 'no two shipped rules share a name');
        $this->assertCount(count($names), RuleSets::rules('strict'), 'strict runs every shipped rule');
    }

    public function testNothingFiredIsZeroAndEmpty(): void
    {
        $verdict = Score::of($this->quiet(), 'strict');

        $this->assertSame(0.0, $verdict['score'], $verdict['rules']);
        $this->assertSame('', $verdict['rules']);
        $this->assertSame([], $verdict['fired']);
    }

    /** Highest points first; equal points by name, so the text is stable. */
    public function testOrderAndFormat(): void
    {
        $ordered = Score::ordered(['B_RULE' => 1.0, 'Z_RULE' => 5.0, 'A_RULE' => 1.0, 'M_RULE' => 2.5]);

        $this->assertSame(['Z_RULE', 'M_RULE', 'A_RULE', 'B_RULE'], array_keys($ordered));
        $this->assertSame('Z_RULE=5.0 M_RULE=2.5 A_RULE=1.0 B_RULE=1.0', Score::format($ordered));
        $this->assertSame('', Score::format([]));
    }

    /** Cut to whole entries at the column's 255 characters. */
    public function testTheRulesTextIsTrimmedToWholeEntries(): void
    {
        $fired = [];

        for ($i = 10; $i < 30; $i++) {
            $fired[sprintf('A_RATHER_LONG_RULE_NAME_%02d', $i)] = 1.0;
        }

        $text = Score::format($fired);

        $this->assertLessThanOrEqual(255, strlen($text));
        $this->assertGreaterThan(200, strlen($text), 'as many entries as fit');

        foreach (explode(' ', $text) as $entry) {
            $this->assertMatchesRegularExpression('/^A_RATHER_LONG_RULE_NAME_\d\d=1\.0$/', $entry, 'an entry was cut');
        }

        $entries = count(explode(' ', $text));
        $this->assertSame(array_slice(array_keys($fired), 0, $entries),
            array_map(fn ($e) => explode('=', $e)[0], explode(' ', $text)), 'the first ones, in order');
    }

    public function testTheThreshold(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach ([
            [5.0, 5.0], ['7.5', 7.5], [' 2.5 ', 2.5], ['', null], [null, null],
            ['not a number', null],
        ] as list($stored, $expected)) {
            $c->set('base', 'spam_threshold', $stored);
            $this->assertSame($expected, Score::threshold(), var_export($stored, true));
        }
    }

    public function testReachingTheThresholdBlocks(): void
    {
        $this->assertTrue(Score::blocks(5.0, 5.0), 'at the threshold');
        $this->assertTrue(Score::blocks(7.5, 5.0));
        $this->assertFalse(Score::blocks(4.9, 5.0));
        $this->assertTrue(Score::blocks(0.1, 0.1));
    }

    public function testABlankThresholdNeverBlocks(): void
    {
        $this->assertFalse(Score::blocks(100.0, null));
        $this->assertFalse(Score::blocks(0.0, null));
    }

    /** A blank threshold still stamps the score on the event. */
    public function testAdmitStampsTheScoreAndABlankThresholdAdmits(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->set('base', 'spam_detection', 'normal');
        $c->set('base', 'spam_threshold', '');

        $event = $this->quiet();
        $event->set('HTTP_USER_AGENT', 'curl/8.4.0');

        $this->assertTrue(Score::admit($event));
        $this->assertIsFloat($event->get('spam_score'));
        $this->assertGreaterThanOrEqual(5.0, $event->get('spam_score'));
        // The parser may call curl a Spider as well, so the token rule is asserted alone.
        $this->assertStringContainsString('UA_ROBOT_TOKEN=5.0', $event->get('spam_rules'));
    }

    /** Every row of a beacon carries its score: session_start, first_visit and a goal event copy it. */
    public function testEveryEventBuiltFromTheBeaconCarriesTheScore(): void
    {
        $carrier = $this->quiet();
        $carrier->set('spam_score', 2.5);
        $carrier->set('spam_rules', 'NO_CLIENT_HINTS=2.5');

        foreach (['session_start', 'first_visit', 'A Goal Named By Its Definition'] as $name) {
            $built = \OWA\Module\Base\Classes\TrackingEventHelpers::materialize($carrier, $name);

            $this->assertSame(2.5, $built->get('spam_score'), $name);
            $this->assertSame('NO_CLIENT_HINTS=2.5', $built->get('spam_rules'), $name);
        }
    }

    /** The edge's header copies are what the rules read. */
    public function testTheHeadersAreReadOffTheEvent(): void
    {
        $event = $this->quiet();
        $event->set('request_sec_fetch_mode', ' no-cors ');
        $event->set('request_sec_ch_ua_platform', '"Windows"');

        $headers = Score::headers($event);

        $this->assertSame('no-cors', $headers['sec-fetch-mode']);
        $this->assertSame('"Windows"', $headers['sec-ch-ua-platform']);
        $this->assertSame('', $headers['sec-ch-ua'], 'a header the request did not carry');
        $this->assertSame('MyApp/1.2', $headers['user-agent']);
        $this->assertSame(['user-agent', 'sec-fetch-mode', 'sec-fetch-site', 'sec-fetch-dest', 'sec-ch-ua',
            'sec-ch-ua-platform', 'sec-ch-ua-mobile', 'accept-encoding', 'accept-language', 'referer'], array_keys($headers));
    }

    /** The edge reads them from the request, and keeps one already on the event. */
    public function testTheEdgeCallbacksReadTheRequest(): void
    {
        $rc    = \OWA\Core\CoreAPI::requestContainerSingleton();
        $saved = $rc->server;

        try {
            $rc->server['HTTP_SEC_FETCH_MODE']     = 'no-cors';
            $rc->server['HTTP_SEC_CH_UA_PLATFORM'] = '"macOS"';
            $rc->server['HTTP_REFERER']            = 'https://example.com/page';
            unset($rc->server['HTTP_ACCEPT_ENCODING']);

            $helpers = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

            $this->assertSame('no-cors', $helpers::requestSecFetchMode(''));
            $this->assertSame('"macOS"', $helpers::requestSecChUaPlatform(''));
            $this->assertSame('https://example.com/page', $helpers::requestReferer(''));
            $this->assertSame('', $helpers::requestAcceptEncoding(''));
            $this->assertSame('cors', $helpers::requestSecFetchMode('cors'));
        } finally {
            $rc->server = $saved;
        }
    }

    /** Registered as request properties, so they ride the queue envelope and a beacon cannot set them. */
    public function testTheCapturedHeadersAreRequestProperties(): void
    {
        $request = \OWA\Module\Base\Classes\TrackingEventHelpers::requestProperties();

        foreach ([
            'request_sec_fetch_mode', 'request_sec_fetch_site', 'request_sec_fetch_dest', 'request_sec_ch_ua',
            'request_sec_ch_ua_platform', 'request_sec_ch_ua_mobile', 'request_accept_encoding',
            'request_accept_language', 'request_referer', 'received_page_location', 'received_referer',
        ] as $name) {
            $this->assertArrayHasKey($name, $request);
            $this->assertSame('', \OWA\Module\Base\Classes\TrackingEventHelpers::columnFor($name), "$name is stored by nothing");
            $this->assertSame([], \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams([$name => 'x']));
        }
    }
}

final class SpamScoreTestRuleSmall extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_SMALL';
    const SET = 'test';
    const POINTS = 2.5;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleLarge extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_LARGE';
    const SET = 'test';
    const POINTS = 4.0;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleNegative extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_NEGATIVE';
    const SET = 'test';
    const POINTS = -1.0;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleSilent extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_SILENT';
    const SET = 'test';
    const POINTS = 9.0;

    public function test($event, array $headers) { return false; }
}

final class SpamScoreTestRuleSmallAgain extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_SMALL';
    const SET = 'other';
    const POINTS = 7.0;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleLongName extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_A_NAME_LONGER_THAN_THE_SIXTY_FOUR_CHARACTERS_OWA_SPAM_BLOCK_HOLDS';
    const SET = 'test';
    const POINTS = 1.0;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleSpacedName extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST SPACED';
    const SET = 'test';
    const POINTS = 1.0;

    public function test($event, array $headers) { return true; }
}

final class SpamScoreTestRuleNoName extends \OWA\Module\Base\Classes\Spam\Rule
{
    const SET = 'test';
    const POINTS = 1.0;

    public function test($event, array $headers) { return true; }
}

abstract class SpamScoreTestRuleAbstract extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_ABSTRACT';
}

final class SpamScoreTestRuleThrows extends \OWA\Module\Base\Classes\Spam\Rule
{
    const NAME = 'TEST_THROWS';
    const SET = 'test';
    const POINTS = 9.0;

    public function test($event, array $headers) { throw new \RuntimeException('broken'); }
}
