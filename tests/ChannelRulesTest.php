<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\CampaignStep;
use OWA\Module\Base\Classes\Cube\ChannelStep;
use OWA\Module\Base\Classes\Cube\Context;
use OWA\Module\Base\Classes\Cube\MediumStep;
use OWA\Module\Base\Classes\Cube\SiteLists;
use OWA\Module\Base\Classes\Cube\SourceStep;

/**
 * Source, medium, campaign and channel, from a visit's tags and referrer.
 *
 * Each case runs the steps' real SQL against the server with the inputs as
 * literals, so every rule is checked on whichever server the suite runs on --
 * MySQL and MariaDB compare and match differently, and the build runs there.
 */
final class ChannelRulesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This runs the SQL on the server.');
        }

        $this->context = new Context(['name' => 'p', 'start' => 20260101, 'end' => 20260102], 1, 1);
    }

    private static function lit(?string $value): string
    {
        return $value === null ? 'NULL' : "'" . \OWA\Core\CoreAPI::dbSingleton()->prepare($value) . "'";
    }

    private function value(string $sql): ?string
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $row = $db->get_row('SELECT ' . $sql . ' AS v');

        $this->assertIsArray($row, 'the server refused it: ' . $db->lastQueryError());

        return $row['v'];
    }

    /** source, medium and campaign of one visit, from its tags and referring host. */
    private function attribution(?string $source_tag, ?string $medium_tag, ?string $campaign_tag, ?string $host,
                                 ?string $ad_tag = null): array
    {
        $source = new SourceStep('source', self::lit($source_tag), self::lit($host), 'session');
        $medium = new MediumStep('medium', self::lit($medium_tag), self::lit($host), 'session');
        $campaign = new CampaignStep('campaign', self::lit($campaign_tag),
            [self::lit($source_tag), self::lit($medium_tag), self::lit($ad_tag)],
            new MediumStep('medium', self::lit($medium_tag), self::lit($host), 'session'), 'session');

        return [
            'source'   => $this->value($source->execute($this->context)),
            'medium'   => $this->value($medium->execute($this->context)),
            'campaign' => $this->value($campaign->execute($this->context)),
        ];
    }

    private function channel(?string $source, ?string $medium, ?string $campaign): string
    {
        return $this->value((new ChannelStep('channel', self::lit($source), self::lit($medium), self::lit($campaign)))
            ->rules($this->context));
    }

    /** @return array case => [source tag, medium tag, campaign tag, host, expected source, medium, campaign, channel] */
    public static function visits(): array
    {
        return [
            'typed or bookmarked'        => [null, null, null, null, '(direct)', '(none)', '(direct)', 'Direct'],
            'a search engine'            => [null, null, null, 'www.google.com', 'www.google.com', 'organic', '(organic)', 'Organic Search'],
            'a social network'           => [null, null, null, 'm.facebook.com', 'm.facebook.com', 'referral', '(referral)', 'Organic Social'],
            'a video site'               => [null, null, null, 'www.youtube.com', 'www.youtube.com', 'referral', '(referral)', 'Organic Video'],
            'a shopping site'            => [null, null, null, 'www.amazon.com', 'www.amazon.com', 'referral', '(referral)', 'Organic Shopping'],
            'an AI assistant'            => [null, null, null, 'chatgpt.com', 'chatgpt.com', 'ai-agent', '(ai-agent)', 'AI Agent'],
            'an assistant on google.com' => [null, null, null, 'gemini.google.com', 'gemini.google.com', 'ai-agent', '(ai-agent)', 'AI Agent'],
            'tagged ai-assistant'        => ['partner', 'ai-assistant', 'autumn', null, 'partner', 'ai-assistant', 'autumn', 'AI Agent'],
            'any other site'             => [null, null, null, 'blog.example', 'blog.example', 'referral', '(referral)', 'Referral'],
            'google / cpc'               => ['google', 'cpc', 'autumn', 'www.google.com', 'google', 'cpc', 'autumn', 'Paid Search'],
            'facebook / cpc'             => ['facebook', 'cpc', 'autumn', null, 'facebook', 'cpc', 'autumn', 'Paid Social'],
            'youtube / cpc'              => ['youtube', 'cpc', 'autumn', null, 'youtube', 'cpc', 'autumn', 'Paid Video'],
            'a newsletter'               => ['newsletter', 'email', 'autumn', null, 'newsletter', 'email', 'autumn', 'Email'],
            'e-mail spelled otherwise'   => ['newsletter', 'E-Mail', 'autumn', null, 'newsletter', 'e-mail', 'autumn', 'Email'],
            'a display banner'           => ['adnet', 'banner', 'autumn', null, 'adnet', 'banner', 'autumn', 'Display'],
            'paid, from anywhere else'   => ['adnet', 'paid-promo', 'autumn', null, 'adnet', 'paid-promo', 'autumn', 'Paid Other'],
            'an affiliate'               => ['partner', 'affiliate', 'autumn', null, 'partner', 'affiliate', 'autumn', 'Affiliates'],
            'an sms'                     => ['sms', 'text', 'autumn', null, 'sms', 'text', 'autumn', 'SMS'],
            'a push notification'        => ['app', 'web-push', 'autumn', null, 'app', 'web-push', 'autumn', 'Mobile Push Notifications'],
            'a cross-network campaign'   => ['google', 'cpc', 'q4-cross-network', null, 'google', 'cpc', 'q4-cross-network', 'Cross-network'],
            'a shopping campaign, paid'  => ['adnet', 'cpc', 'spring-shopping', null, 'adnet', 'cpc', 'spring-shopping', 'Paid Shopping'],
            'a tagged social medium'     => ['partner', 'social-network', 'autumn', null, 'partner', 'social-network', 'autumn', 'Organic Social'],
            'a medium no rule names'     => ['partner', 'carrier-pigeon', 'autumn', null, 'partner', 'carrier-pigeon', 'autumn', 'Unassigned'],
        ];
    }

    /** @dataProvider visits */
    public function testEachVisitIsAttributed(?string $st, ?string $mt, ?string $ct, ?string $host,
                                                       string $source, string $medium, string $campaign, string $channel): void
    {
        $got = $this->attribution($st, $mt, $ct, $host);

        $this->assertSame(['source' => $source, 'medium' => $medium, 'campaign' => $campaign], $got);
        $this->assertSame($channel, $this->channel($got['source'], $got['medium'], $got['campaign']));
    }

    /**
     * A TAGGED VISIT WITH NO CAMPAIGN IS (not set) -- NULL -- not
     * (referral). So the (referral) campaign row counts exactly the
     * referral medium's untagged sessions, and a link missing its utm_campaign
     * is visible rather than hidden in referral.
     */
    public function testATaggedVisitWithNoCampaignIsNotSet(): void
    {
        $got = $this->attribution('newsletter', 'email', null, null);

        $this->assertNull($got['campaign']);
        $this->assertSame('email', $got['medium']);
        $this->assertSame('Email', $this->channel($got['source'], $got['medium'], $got['campaign']));

        $this->assertNull($this->attribution(null, null, null, null, 'banner-b')['campaign'],
            'any tag makes the visit tagged, the ad included');
    }

    /** A site tagging utm_source=direct is not the generated (direct). */
    public function testATaggedDirectSourceIsNotTheGeneratedOne(): void
    {
        $got = $this->attribution('direct', 'email', 'autumn', null);

        $this->assertSame('direct', $got['source']);
        $this->assertSame('Email', $this->channel($got['source'], $got['medium'], $got['campaign']));
    }

    /** The sentinel -- an acquisition never captured -- stays the sentinel. */
    public function testAnUnresolvedAcquisitionHasNoChannel(): void
    {
        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertSame($sentinel, $this->channel($sentinel, $sentinel, $sentinel));
    }

    /**
     * WHOLE LABELS, NOT SUBSTRINGS. The build used to test containment, so
     * `t.co` matched microsoft.com and every Microsoft referral read as social.
     */
    public function testListsMatchWholeLabels(): void
    {
        $on = fn (string $value, string $list) =>
            (int) $this->value(SiteLists::matches(self::lit($value), $list, $this->context));

        $this->assertSame(1, $on('t.co', 'social'));
        $this->assertSame(1, $on('x.t.co', 'social'));
        $this->assertSame(0, $on('microsoft.com', 'social'));
        $this->assertSame(1, $on('www.google.com', 'search'));
        $this->assertSame(1, $on('google', 'search'), 'a tagged source by name');
        $this->assertSame(0, $on('googleusercontent.com', 'search'));
        $this->assertSame(0, $on('dropbox.com', 'social'), 'x.com is not inside it');
    }

    /** The channel list is the configured rules' names, in order, then Unassigned. */
    public function testTheChannelListIsTheRulesInOrder(): void
    {
        $names = array_column(include OWA_CONF_DIR . 'channels.php', 'channel');

        $this->assertSame(array_merge($names, ['Unassigned']), ChannelStep::channels());
        $this->assertSame(['Direct', 'Cross-network'], array_slice($names, 0, 2));
        $this->assertLessThan(array_search('Organic Search', $names), array_search('AI Agent', $names),
            'an assistant is tested before search, so gemini.google.com is not Organic Search');
    }

    /** The rules are configuration: a rule set of the site's own is what decides. */
    public function testASiteOwnRulesDecide(): void
    {
        $rules = [
            ['channel' => 'Partners', 'all' => [
                ['source', 'starts_with', 'Partner'],
                ['any' => [['medium', 'equals', 'Referral'], ['medium', 'ends_with', '-link']]],
            ]],
            ['channel' => 'Newsletters', 'any' => [['campaign', 'regex', '^news-[0-9]+$']]],
            ['channel' => 'Search', 'any' => [['source', 'in_list', 'search']]],
            ['channel' => 'Literal', 'any' => [['medium', 'contains', '50%_off']]],
        ];

        $on = fn (?string $source, ?string $medium, ?string $campaign): string => $this->value(
            (new ChannelStep('channel', self::lit($source), self::lit($medium), self::lit($campaign)))
                ->rules($this->context, $rules));

        $this->assertSame('Partners', $on('partner-bob', 'referral', null));
        $this->assertSame('Partners', $on('PARTNER-alice', 'text-link', null), 'values compare lowercased');
        $this->assertSame('Unassigned', $on('partner-bob', 'email', null), 'all needs every condition');
        $this->assertSame('Newsletters', $on('x', 'email', 'news-12'));
        $this->assertSame('Unassigned', $on('x', 'email', 'news-12b'));
        $this->assertSame('Search', $on('www.google.com', 'organic', null));
        $this->assertSame('Literal', $on('x', 'spring-50%_off', null));
        $this->assertSame('Unassigned', $on('x', 'spring-50x off', null), 'LIKE wildcards in a value are literal');
        $this->assertSame('Unassigned', $on(null, '(none)', null), 'no Direct unless a rule says so');
        $this->assertSame(['Partners', 'Newsletters', 'Search', 'Literal', 'Unassigned'], ChannelStep::channels($rules));
    }

    /** A file of the same name in the data directory replaces the shipped rules. */
    public function testTheDataDirectoryFileReplacesTheShippedOne(): void
    {
        $dir = sys_get_temp_dir() . '/owa-channels-' . bin2hex(random_bytes(4)) . '/';
        mkdir($dir);
        file_put_contents($dir . 'channels.php',
            "<?php return [['channel' => 'Everything', 'any' => [['medium', 'contains', '']]]];");

        try {
            $this->assertSame([['channel' => 'Everything', 'any' => [['medium', 'contains', '']]]],
                ChannelStep::definitions($dir));
        } finally {
            unlink($dir . 'channels.php');
            rmdir($dir);
        }

        $this->assertSame(include OWA_CONF_DIR . 'channels.php', ChannelStep::definitions($dir),
            'without one, the shipped rules');
    }

    /** @return array case => [rules, what the refusal names] */
    public static function badRules(): array
    {
        return [
            'not a list'       => [[], 'not a list'],
            'no name'          => [[['any' => [['medium', 'equals', 'x']]]], 'needs a name'],
            'Unassigned'       => [[['channel' => 'Unassigned', 'any' => [['medium', 'equals', 'x']]]], 'not Unassigned'],
            'too long a name'  => [[['channel' => str_repeat('x', 33), 'any' => [['medium', 'equals', 'x']]]], '1 to 32'],
            'no conditions'    => [[['channel' => 'A']], 'no any or all'],
            'empty conditions' => [[['channel' => 'A', 'any' => []]], 'empty condition list'],
            'unknown field'    => [[['channel' => 'A', 'any' => [['keyword', 'equals', 'x']]]], 'tests "keyword"'],
            'unknown operator' => [[['channel' => 'A', 'any' => [['medium', 'like', 'x']]]], 'operator "like"'],
            'unknown list'     => [[['channel' => 'A', 'any' => [['source', 'in_list', 'news']]]], 'site list "news"'],
            'nested unknown'   => [[['channel' => 'A', 'all' => [['any' => [['medium', 'is', 'x']]]]]], 'rule 1 (A) uses operator "is"'],
        ];
    }

    /** @dataProvider badRules */
    public function testARuleTheCompilerCannotReadIsRefused(array $rules, string $names): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($names);

        (new ChannelStep('channel', 'source', 'medium', 'campaign'))->rules($this->context, $rules);
    }
}
