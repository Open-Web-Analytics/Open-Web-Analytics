<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\SettingsForm;

/**
 * Settings rendered below the install: the Override switch.
 *
 * An inheriting setting shows the value in force, disabled, with a line naming
 * the level that sets it and an Override switch beside it, off. With the
 * switch on, the control is editable and the value is this level's own. On
 * save the switch decides: on stores, off removes.
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
            $p->setAccessible(true);
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
            $p->setAccessible(true);
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

    public function testAnInheritingFieldIsDisabledWithItsSwitchOff(): void
    {
        $this->requireDb();

        $html = $this->field('words');

        $this->assertStringContainsString(
            'name="owa_config[zz_scoped_form_test.words]" value="abc" disabled="disabled"', $html);
        $this->assertStringContainsString('data-owa-inherited="abc"', $html);
        $this->assertMatchesRegularExpression('#<input type="checkbox" role="switch" name="owa_override\[zz_scoped_form_test\.words\]" value="1" data-owa-override="[^"]+"> Override#', $html,
            'the switch is beside the field, off');
        $this->assertMatchesRegularExpression('#data-owa-note-inherit="[^"]+">Currently set at the install level\.</div>#', $html,
            'the note beneath names the level that sets it, and shows');
        $this->assertMatchesRegularExpression('#data-owa-note-override="[^"]+" hidden>#', $html);
    }

    public function testAnOverriddenFieldIsEditableWithItsSwitchOn(): void
    {
        $this->requireDb();

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'words', 'mine');

        $html = $this->field('words');

        $this->assertStringContainsString('name="owa_config[zz_scoped_form_test.words]" value="mine" id=', $html,
            'its own value, not disabled');
        $this->assertStringContainsString('data-owa-inherited="abc"', $html,
            'switching off puts back what it would inherit');
        $this->assertStringContainsString('value="1" data-owa-override="owa-setting-zz_scoped_form_test-words" checked="checked"', $html);
        $this->assertMatchesRegularExpression('#data-owa-note-inherit="[^"]+" hidden>#', $html);
        $this->assertStringContainsString('>Overrides the install level value of <code>abc</code>.</div>', $html);
    }

    /** With nothing set above, an override overrides nothing, so it gets no note. */
    public function testOverridingAnEmptyValueHasNoNote(): void
    {
        $this->requireDb();

        $this->config()->registerField(self::MODULE, 'blank', array(
            'default' => '', 'storable' => true, 'type' => 'text', 'label' => 'Blank',
            'scopes'  => array('install', 'profile')));

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::PROFILE, self::MODULE, 'blank', 'mine');

        try {
            $html = $this->field('blank');

            $this->assertStringContainsString('value="mine" id=', $html);
            $this->assertStringNotContainsString('data-owa-note-override', $html);
            $this->assertStringNotContainsString('Overrides', $html);
        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::PROFILE, self::MODULE, 'blank');
        }
    }

    public function testABooleanSaysOnOrOff(): void
    {
        $this->requireDb();

        $html = $this->field('flag');

        $this->assertStringContainsString('<option value="1" selected="selected">On</option>', $html);
        $this->assertStringContainsString('data-owa-inherited="1"', $html);

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
            $this->assertStringContainsString('>Currently set at the Property level.</div>', $html);

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('property', $propertyId, self::MODULE, 'words');
        }
    }

    /** A Property being created holds nothing yet, so it inherits the install. */
    public function testALevelNotCreatedYetInheritsTheInstall(): void
    {
        $html = SettingsForm::scopedField(self::MODULE, 'property_only', 'property', '', 'owa_');

        $this->assertStringContainsString('value="p" disabled="disabled"', $html);
        $this->assertStringContainsString('>Currently set at the install level.</div>', $html);
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
            $this->assertStringContainsString('disabled="disabled"', $html);
            $this->assertStringNotContainsString('override[', $html);

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

        $this->assertStringContainsString('value="typed" id=', $html);
        $this->assertStringContainsString('checked="checked"', $html);
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
