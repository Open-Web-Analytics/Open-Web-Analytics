<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Settings;
use OWA\Module\Base\Classes\SettingsForm;

/**
 * A `map` setting: name => value, typed on a screen as name=value pairs in one
 * text box. campaignKeys is the one Base declares, keyed by the campaign roles
 * TrackingEventHelpers reads.
 */
final class SettingsMapTypeTest extends TestCase
{
    private const PROFILE = 'zz-map-type-profile';

    private function config()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    private function roles(): string
    {
        return implode(', ', array_keys(\OWA\Module\Base\Classes\TrackingEventHelpers::CAMPAIGN_KEYS));
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, 'base', 'campaignKeys');
        }
    }

    public function testCampaignKeysIsAMapOfTheCampaignRoles(): void
    {
        $args = $this->config()->registeredField('base', 'campaignKeys');

        $this->assertSame('map', $args['type'] ?? null);
        $this->assertSame(array_keys(\OWA\Module\Base\Classes\TrackingEventHelpers::CAMPAIGN_KEYS), $args['keys'] ?? null);
    }

    public function testTypedPairsAreRead(): void
    {
        $args = $this->config()->registeredField('base', 'campaignKeys');

        $this->assertSame(array('source' => 'src', 'medium' => 'channel'),
            Settings::mapPairs($args, ' source=src,medium = channel , '));
        $this->assertSame(array(), Settings::mapPairs($args, ''));
        $this->assertSame(array('source' => 'second'), Settings::mapPairs($args, 'source=first, source=second'),
            'a later pair for a name replaces an earlier one');
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return array(
            'an unknown role'      => array('sorce=src'),
            'no value'             => array('source='),
            'no name'              => array('=src'),
            'no pair'              => array('source'),
            'a space in the value' => array('source=a b'),
            'two equals signs'     => array('source=a=b'),
        );
    }

    /** @dataProvider malformed */
    public function testTextNotInThatFormIsRefusedNamingTheRoles(string $text): void
    {
        $this->assertSame(
            'Campaign Parameter Names is a comma-separated list of name=value pairs, each name one of ' . $this->roles() . '.',
            $this->config()->valueProblem('base', 'campaignKeys', $text));
    }

    public function testEmptyTextIsNoPairs(): void
    {
        $this->assertNull($this->config()->valueProblem('base', 'campaignKeys', ''));
        $this->assertSame(array(), $this->config()->normalizedValue('base', 'campaignKeys', ''));
    }

    /** Stored, a map holds only its declared keys, in their order. */
    public function testAStoredMapKeepsOnlyItsDeclaredKeys(): void
    {
        $this->assertNull($this->config()->valueProblem('base', 'campaignKeys', array('not_a_role' => 'x')),
            'a list is not refused: it is what is already stored');

        $this->assertSame(array('source' => 'src', 'medium' => 'channel'),
            $this->config()->normalizedValue('base', 'campaignKeys',
                array('medium' => 'channel', 'not_a_role' => 'x', 'source' => ' src ', 'ad' => '')));

        $this->assertSame(array('source' => 'src', 'medium' => 'channel'),
            $this->config()->normalizedValue('base', 'campaignKeys', 'medium=channel, source=src'));
    }

    /**
     * Inherited, the control shows the map as pairs, and so do the value the
     * switch puts back and the note naming what an override replaces.
     */
    public function testTheControlShowsTheMapAsPairs(): void
    {
        $was = $this->config()->get('base', 'campaignKeys');

        try {
            $this->config()->set('base', 'campaignKeys', array('medium' => 'channel', 'not_a_role' => 'x', 'source' => 'src'));

            $html = SettingsForm::scopedField('base', 'campaignKeys', 'profile', '', 'owa_');

            $this->assertStringContainsString(
                '<input type="text" size="50" name="owa_config[base.campaignKeys]" value="source=src, medium=channel" disabled="disabled"', $html);
            $this->assertStringContainsString('data-owa-inherited="source=src, medium=channel"', $html);
            $this->assertStringContainsString('<code>source=src, medium=channel</code>', $html);
            $this->assertStringNotContainsString('Array', $html);

        } finally {
            $this->config()->set('base', 'campaignKeys', $was);
        }
    }

    /** Nothing set above: a plain, empty field. */
    public function testWithNothingAboveTheFieldIsPlainAndEmpty(): void
    {
        $was = $this->config()->get('base', 'campaignKeys');

        try {
            $this->config()->set('base', 'campaignKeys', array());

            $html = SettingsForm::scopedField('base', 'campaignKeys', 'profile', '', 'owa_');

            $this->assertStringContainsString('name="owa_config[base.campaignKeys]" value="" id=', $html);
            $this->assertStringNotContainsString('override[', $html);

        } finally {
            $this->config()->set('base', 'campaignKeys', $was);
        }
    }

    /** The write chokepoint holds every caller to the form, and stores the map. */
    public function testAScopedWriteRefusesBadTextAndStoresTheMap(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes a scoped setting row');
        }

        $this->assertFalse(\OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, 'base', 'campaignKeys', 'sorce=src'));
        $this->assertNull(\OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, 'base', 'campaignKeys'));

        $this->assertTrue((bool) \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, 'base', 'campaignKeys', 'source=src'));
        $this->assertSame(array('source' => 'src'),
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, 'base', 'campaignKeys'));
    }
}
