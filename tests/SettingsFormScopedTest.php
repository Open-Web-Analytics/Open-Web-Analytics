<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\SettingsForm;

/**
 * Settings rendered below the install: the Override switch.
 *
 * An inheriting setting shows the value in force as text, with the level that
 * sets it beside it and an Override switch, off; its control is in the page,
 * hidden and disabled. With the switch on, the control shows, editable, and the
 * value is this level's own. On save the switch decides: on stores, off removes.
 */
final class SettingsFormScopedTest extends TestCase
{
    private const MODULE = 'zz_scoped_form_test';
    private const PROFILE = 'zz-scoped-profile';

    /** @var array<string,mixed> */
    private array $snapshot = array();

    protected function setUp(): void
    {
        $this->snapshot = array();

        foreach (array('registry', 'fieldsets') as $property) {

            $p = new ReflectionProperty(\OWA\Module\Base\Classes\Settings::class, $property);
            $this->snapshot[$property] = $p->getValue($this->config());
        }

        $this->config()->registerField(self::MODULE, 'words', array(
            'default' => 'abc', 'storable' => true, 'type' => 'text', 'label' => 'Words',
            'scopes'  => array('install', 'property', 'profile')));

        $this->config()->registerField(self::MODULE, 'flag', array(
            'default' => true, 'storable' => true, 'type' => 'boolean', 'label' => 'Flag',
            'scopes'  => array('install', 'property', 'profile')));

        $this->config()->registerField(self::MODULE, 'property_only', array(
            'default' => 'p', 'storable' => true, 'type' => 'text', 'label' => 'Property only',
            'scopes'  => array('install', 'property')));
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {

            foreach (array('words', 'flag', 'property_only') as $key) {
                \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, self::MODULE, $key);
            }
        }

        foreach ($this->snapshot as $property => $value) {

            $p = new ReflectionProperty(\OWA\Module\Base\Classes\Settings::class, $property);
            $p->setValue($this->config(), $value);
        }
    }

    private function config()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('reads and writes scoped setting rows');
        }
    }

    private function set(): array
    {
        return array('id' => self::MODULE . '.set', 'module' => self::MODULE,
                     'settings' => array('words', 'flag', 'property_only'));
    }

    private function field(string $key, $posted = null): string
    {
        return SettingsForm::scopedField(self::MODULE, $key, 'profile', self::PROFILE, 'owa_', $posted);
    }

    public function testAnInheritingFieldShowsItsValueAsTextWithItsSwitchOff(): void
    {
        $this->requireDb();

        $html = $this->field('words');
        $id   = 'owa-setting-zz_scoped_form_test-words';

        $this->assertStringContainsString('<output class="owa-inherited" id="' . $id . '-inherited"'
            . ' aria-labelledby="' . $id . '-title" aria-describedby="' . $id . '-note-inherit"'
            . ' aria-live="off" data-ui="setting-inherited">abc</output>', $html,
            'the value in force, as text, labelled by the title and described by the level');
        $this->assertStringContainsString(
            'name="owa_config[zz_scoped_form_test.words]" value="abc" disabled="disabled" id="' . $id . '" data-owa-inherited="abc" hidden>', $html,
            'the control is there, hidden and disabled, so nothing posts');
        $this->assertStringContainsString('<input type="checkbox" role="switch" name="owa_override[zz_scoped_form_test.words]" value="1"'
            . ' data-owa-override="' . $id . '" data-ui="setting-override" aria-label="Override Words"'
            . ' aria-describedby="' . $id . '-inherited ' . $id . '-note-inherit"> Override</label>', $html,
            'the switch is off, named for the setting and described by what is in force');
        $this->assertStringContainsString('<span class="owa-inherit-note" id="' . $id . '-note-inherit" data-owa-note-inherit="' . $id . '">Set at the install level.</span>', $html,
            'the note beside the value names the level that sets it');
        $this->assertMatchesRegularExpression('#data-owa-note-override="[^"]+" hidden>#', $html);
        $this->assertStringContainsString('data-ui="setting:zz_scoped_form_test.words"', $html);
    }

    /** The title labels the control, whether it inherits or has nothing above it. */
    public function testTheTitleLabelsTheControl(): void
    {
        $this->requireDb();
        $this->registerBlank();

        foreach (array('words' => 'Words', 'blank' => 'Blank') as $key => $label) {

            $id = 'owa-setting-zz_scoped_form_test-' . $key;
            $html = $this->field($key);

            $this->assertStringContainsString('<label class="title" id="' . $id . '-title" for="' . $id . '">' . $label . '</label>', $html);
            $this->assertMatchesRegularExpression('#<input type="text"[^>]* id="' . $id . '"#', $html);
        }
    }

    public function testAnOverriddenFieldIsEditableWithItsSwitchOn(): void
    {
        $this->requireDb();

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'words', 'mine');

        $html = $this->field('words');

        $this->assertStringContainsString('name="owa_config[zz_scoped_form_test.words]" value="mine" id="owa-setting-zz_scoped_form_test-words" data-owa-inherited="abc">', $html,
            'its own value, shown and not disabled; switching off puts back what it would inherit');
        $this->assertMatchesRegularExpression('#<output [^>]*data-ui="setting-inherited" hidden>abc</output>#', $html,
            'the inherited text is hidden');
        $this->assertStringContainsString('data-owa-override="owa-setting-zz_scoped_form_test-words" data-ui="setting-override" aria-label="Override Words"'
            . ' aria-describedby="owa-setting-zz_scoped_form_test-words-note-override" checked="checked"', $html);
        $this->assertMatchesRegularExpression('#data-owa-note-inherit="[^"]+" hidden>#', $html);
        $this->assertStringContainsString('>Overrides the install level value of <code>abc</code>.</div>', $html);
    }

    private function registerBlank(): void
    {
        $this->config()->registerField(self::MODULE, 'blank', array(
            'default' => '', 'storable' => true, 'type' => 'text', 'label' => 'Blank',
            'scopes'  => array('install', 'profile')));
    }

    /** With nothing set above there is nothing to override: a plain field. */
    public function testWithNothingAboveTheFieldIsPlain(): void
    {
        $this->requireDb();
        $this->registerBlank();

        try {
            $html = $this->field('blank');

            $this->assertStringContainsString('name="owa_config[zz_scoped_form_test.blank]" value="" id=', $html,
                'editable, not disabled');
            $this->assertStringNotContainsString('override[', $html, 'no switch');
            $this->assertStringNotContainsString('owa-inherit-note', $html, 'no note');
            $this->assertStringNotContainsString('setting-inherited', $html, 'no inherited text');
            $this->assertStringNotContainsString(' hidden', $html, 'nothing hidden');

            \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'blank', 'mine');

            $html = $this->field('blank');

            $this->assertStringContainsString('value="mine" id=', $html);
            $this->assertStringNotContainsString('override[', $html);

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, self::MODULE, 'blank');
        }
    }

    /**
     * The mode is read from what is set now. A value saved here while nothing
     * was set above becomes an override once something is.
     */
    public function testAValueSetAboveLaterTurnsThisLevelsValueIntoAnOverride(): void
    {
        $this->requireDb();
        $this->registerBlank();

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'blank', 'mine');

        try {
            $this->assertStringNotContainsString('override[', $this->field('blank'));

            $this->config()->set(self::MODULE, 'blank', 'above');

            $html = $this->field('blank');

            $this->assertStringContainsString('value="mine" id=', $html);
            $this->assertMatchesRegularExpression('#name="owa_override\[zz_scoped_form_test\.blank\]" value="1" data-owa-override="[^"]+"[^>]* checked="checked"#', $html);
            $this->assertStringContainsString('>Overrides the install level value of <code>above</code>.</div>', $html);

        } finally {
            $this->config()->set(self::MODULE, 'blank', '');
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, self::MODULE, 'blank');
        }
    }

    /** A setting that declares no default answers false: nothing is set, so it is plain. */
    public function testASettingWithNoDefaultIsPlain(): void
    {
        $this->requireDb();

        $this->config()->registerField(self::MODULE, 'no_default', array(
            'storable' => true, 'type' => 'text', 'label' => 'No default',
            'scopes'   => array('install', 'profile')));

        $html = $this->field('no_default');

        $this->assertStringContainsString('value="" id=', $html);
        $this->assertStringNotContainsString('override[', $html);
    }

    /** A plain field saves by its value: something stores it, empty removes it. */
    public function testAPlainFieldIsSavedByItsValue(): void
    {
        $this->requireDb();
        $this->registerBlank();

        $set = array('id' => self::MODULE . '.blankset', 'module' => self::MODULE, 'settings' => array('blank'));
        $row = fn () => \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'blank');

        try {
            SettingsForm::saveScoped($set, 'profile', self::PROFILE, array(self::MODULE . '.blank' => 'mine'), array());
            $this->assertSame('mine', $row(), 'no switch is needed to store it');

            SettingsForm::saveScoped($set, 'profile', self::PROFILE, array(), array());
            $this->assertSame('mine', $row(), 'a post without the field leaves it alone');

            SettingsForm::saveScoped($set, 'profile', self::PROFILE, array(self::MODULE . '.blank' => '  '), array());
            $this->assertNull($row(), 'saved empty, the level holds nothing');

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, self::MODULE, 'blank');
        }
    }

    public function testABooleanSaysOnOrOff(): void
    {
        $this->requireDb();

        $html = $this->field('flag');

        $this->assertStringContainsString('<option value="1" selected="selected">On</option>', $html);
        $this->assertStringContainsString('data-owa-inherited="1" hidden>', $html);
        $this->assertMatchesRegularExpression('#data-ui="setting-inherited">On</output>#', $html, 'shown as On, not 1');

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'flag', false);

        $html = $this->field('flag');

        $this->assertStringContainsString('<option value="0" selected="selected">Off</option>', $html,
            'a stored false is this level\'s value');
        $this->assertStringContainsString('>Overrides the install level value of <code>On</code>.</div>', $html);
    }

    /** The level named is the one actually supplying the value. */
    public function testTheNoteNamesTheNearestLevelThatSetsIt(): void
    {
        $this->requireDb();

        $site = null;

        foreach ((array) \OWA\Core\CoreAPI::getSitesList() as $row) {
            $id = (string) (is_array($row) ? $row['site_id'] : $row);
            $chain = \OWA\Core\CoreAPI::settingScopeChain('profile', $id);
            if (isset($chain[1]) && $chain[1]['type'] === 'property') {
                $site = array($id, (string) $chain[1]['id']);
                break;
            }
        }

        if (!$site) {
            $this->markTestSkipped('needs a Profile under a Property');
        }

        [$siteId, $propertyId] = $site;

        \OWA\Core\CoreAPI::setScopedSetting('property', $propertyId, self::MODULE, 'words', 'from the property');

        try {
            $html = SettingsForm::scopedField(self::MODULE, 'words', 'profile', $siteId, 'owa_');

            $this->assertStringContainsString('value="from the property" disabled="disabled"', $html);
            $this->assertMatchesRegularExpression('#data-ui="setting-inherited">from the property</output>#', $html);
            $this->assertStringContainsString('>Set at the Property level.</span>', $html);

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('property', $propertyId, self::MODULE, 'words');
        }
    }

    /** A Property being created holds nothing yet, so it inherits the install. */
    public function testALevelNotCreatedYetInheritsTheInstall(): void
    {
        $html = SettingsForm::scopedField(self::MODULE, 'property_only', 'property', '', 'owa_');

        $this->assertStringContainsString('value="p" disabled="disabled"', $html);
        $this->assertStringContainsString('>Set at the install level.</span>', $html);
    }

    /**
     * The inherited text is the value as the screen names it.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function shownValues(): array
    {
        return array(
            'a select, by its option\'s label' => array(
                array('type' => 'select', 'options' => array('a' => 'Alpha', 'b' => 'Beta'), 'default' => 'b'), 'Beta'),
            'a boolean false' => array(array('type' => 'boolean', 'default' => false), 'Off'),
            'a list, comma-separated' => array(array('type' => 'text', 'default' => array('x', 'y')), 'x, y'),
            'a timezone, by its option\'s label' => array(array('type' => 'timezone', 'default' => 'America/Los_Angeles'), 'America/Los Angeles'),
            'a number' => array(array('type' => 'integer', 'default' => 0), '0'),
            'text, escaped' => array(array('type' => 'text', 'default' => '<b>&'), '&lt;b&gt;&amp;'),
        );
    }

    /**
     * @dataProvider shownValues
     * @param array<string, mixed> $declaration
     */
    public function testTheInheritedTextNamesTheValue(array $declaration, string $shown): void
    {
        // A key per data set: the configuration keeps the first value it read for a key.
        $key = 'shown_' . substr(md5(serialize($declaration)), 0, 8);

        $this->config()->registerField(self::MODULE, $key, $declaration + array(
            'storable' => true, 'label' => 'Shown', 'scopes' => array('install', 'property')));

        $html = SettingsForm::scopedField(self::MODULE, $key, 'property', '', 'owa_');

        $this->assertStringContainsString(' data-ui="setting-inherited">' . $shown . '</output>', $html);
        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringContainsString('<code>' . $shown . '</code>', $html, 'and the note an override shows');
    }

    /** A long value is cut to one line until it is focused, so it takes the focus; the whole value is in the page. */
    public function testALongValueIsCutAndFocusable(): void
    {
        $long = trim(str_repeat('word ', 20));

        $this->config()->registerField(self::MODULE, 'long', array(
            'type' => 'text', 'default' => $long, 'storable' => true, 'label' => 'Long',
            'scopes' => array('install', 'property')));

        $html = SettingsForm::scopedField(self::MODULE, 'long', 'property', '', 'owa_');

        $this->assertMatchesRegularExpression('#<output class="owa-inherited owa-inherited--long" [^>]* tabindex="0">' . $long . '</output>#', $html);

        $short = SettingsForm::scopedField(self::MODULE, 'property_only', 'property', '', 'owa_');

        $this->assertStringNotContainsString('tabindex', $short, 'a short value is not a tab stop');
        $this->assertStringNotContainsString('owa-inherited--long', $short);
    }

    public function testALevelTheSettingDoesNotDeclareRendersNothing(): void
    {
        $this->assertSame('', $this->field('property_only'));
    }

    /** No level can override a constant, so there is no switch to offer. */
    public function testAConstantGovernedFieldHasNoSwitch(): void
    {
        $this->config()->noteConfigConstant(self::MODULE, 'words', 'OWA_ZZ_SCOPED');

        try {
            $html = $this->field('words');

            $this->assertStringContainsString('OWA_ZZ_SCOPED', $html);
            $this->assertStringContainsString('value="abc" disabled="disabled" id=', $html, 'the control itself, read-only');
            $this->assertStringNotContainsString('override[', $html);
            $this->assertStringNotContainsString('setting-inherited', $html, 'no inherited text');
            $this->assertStringNotContainsString(' hidden', $html);

        } finally {
            $this->config()->forgetConfigConstant(self::MODULE, 'words');
        }
    }

    /** A refused save redraws what was sent: the switch and the typed value. */
    public function testARedisplayShowsThePostedSwitchAndValue(): void
    {
        $this->requireDb();

        $html = $this->field('words', array(
            'config'   => array(self::MODULE . '.words' => 'typed'),
            'override' => array(self::MODULE . '.words' => '1')));

        $this->assertStringContainsString('value="typed" id="owa-setting-zz_scoped_form_test-words" data-owa-inherited="abc">', $html,
            'the control shows, enabled');
        $this->assertStringContainsString('checked="checked"', $html);
        $this->assertMatchesRegularExpression('#data-ui="setting-inherited" hidden>#', $html);

        $html = $this->field('words', array(
            'config'   => array(),
            'override' => array()));

        $this->assertStringContainsString('value="abc" disabled="disabled" id="owa-setting-zz_scoped_form_test-words" data-owa-inherited="abc" hidden>', $html,
            'switched off, the control is hidden and holds the inherited value');
        $this->assertMatchesRegularExpression('#data-ui="setting-inherited">abc</output>#', $html);
    }

    public function testSwitchedOnStoresTheValueEvenWhenItMatches(): void
    {
        $this->requireDb();

        $this->assertTrue(SettingsForm::saveScoped($this->set(), 'profile', self::PROFILE,
            array(self::MODULE . '.words' => 'abc'), array(self::MODULE . '.words' => '1')));

        $this->assertSame('abc',
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'words'),
            'the switch is the decision, not a comparison with the inherited value');
    }

    /** Without script the control stays disabled and posts nothing: keep what it showed. */
    public function testSwitchedOnWithNoValueKeepsWhatItShowed(): void
    {
        $this->requireDb();

        SettingsForm::saveScoped($this->set(), 'profile', self::PROFILE,
            array(), array(self::MODULE . '.flag' => '1'));

        $this->assertTrue(
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'flag'));
    }

    public function testSwitchedOffRemovesThisLevelsValue(): void
    {
        $this->requireDb();

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'words', 'mine');

        $this->assertTrue(SettingsForm::saveScoped($this->set(), 'profile', self::PROFILE, array(), array()));

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'words'));
    }

    public function testABooleanIsStoredAsOne(): void
    {
        $this->requireDb();

        SettingsForm::saveScoped($this->set(), 'profile', self::PROFILE,
            array(self::MODULE . '.flag' => '0'), array(self::MODULE . '.flag' => '1'));

        $this->assertFalse(
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'flag'));
    }

    /** Only what the fieldset holds, at levels it declares. */
    public function testASaveTouchesOnlyWhatTheScreenOffers(): void
    {
        $this->requireDb();

        SettingsForm::saveScoped($this->set(), 'profile', self::PROFILE,
            array(self::MODULE . '.property_only' => 'x', self::MODULE . '.other' => 'y'),
            array(self::MODULE . '.property_only' => '1', self::MODULE . '.other' => '1'));

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'property_only'));
        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow('profile', self::PROFILE, self::MODULE, 'other'));
    }

    /** The two screens build their fields from the registry. */
    public function testTheScopedScreensDoNotHandWriteControls(): void
    {
        foreach (array(
            'modules/Base/templates/profile_settings.php' => 'base.profileObservation',
            'modules/Base/templates/property_profile.php' => 'base.propertyAttribution',
        ) as $template => $set) {

            $code = php_strip_whitespace(OWA_DIR . $template);

            $this->assertStringNotContainsString('config[', $code, $template);
            $this->assertStringContainsString('SettingsForm::scopedFieldSet', $code, $template);
            $this->assertNotSame(array(), SettingsForm::registeredFieldSet($set), "$set is registered");
        }
    }
}
