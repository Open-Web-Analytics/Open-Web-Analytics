<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Settings screens are built from the registry rather than written out.
 *
 * Every field on the general options page used to be a hand-written block: a
 * title, a description, and a control whose submit name was assembled by hand
 * as `config[base.key]`. Eight of them, and each an independent chance to name
 * a setting the registry does not have -- which does not error. It saves a
 * value nothing reads.
 *
 * WHAT THESE PIN. That the conversion lost nothing (every field still renders,
 * under the same submit name, showing the stored value), that the renderer
 * escapes what comes out of the database, and that the one behaviour it CHANGED
 * is the one intended: a setting a config-file constant governs is disabled on
 * every field now, not just on the one field somebody hand-wrote the check for.
 */
final class SettingsFormTest extends TestCase
{
    private const MODULE = 'zz_form_test';

    /** @var array<string,mixed> */
    private array $snapshot = array();

    /**
     * The probes below register into the SINGLETON, because that is what the
     * renderer reads. Snapshot and restore, or they are real entries as far as
     * every later test in the process is concerned.
     */
    protected function setUp(): void
    {
        $this->snapshot = array();

        foreach (array('registry', 'fieldsets') as $property) {

            $p = new ReflectionProperty(\OWA\Module\Base\Classes\Settings::class, $property);
            $p->setAccessible(true);
            $this->snapshot[$property] = $p->getValue($this->config());
        }
    }

    protected function tearDown(): void
    {
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

    private function generalPage(): string
    {
        $html = '';

        foreach (\OWA\Module\Base\Classes\SettingsForm::pageFieldSets('base.optionsGeneral') as $set) {

            $html .= \OWA\Module\Base\Classes\SettingsForm::fieldSet($set, 'owa_');
        }

        return $html;
    }

    /**
     * The conversion is only safe if nothing was dropped on the way. These are
     * the fields the hand-written template carried, and the submit names it
     * used -- a renamed key saves a setting nothing reads.
     */
    public function testEveryFieldTheOldPageHadStillRenders(): void
    {
        $html = $this->generalPage();

        foreach (array(
            'resolve_hosts', 'log_robots', 'log_named_users', 'excluded_ips',
            'anonymize_ips', 'query_string_filters', 'announce_visitors',
            'notice_email', 'timezone',
        ) as $key) {

            $this->assertStringContainsString('id="' . $key . '"', $html,
                $key . ' lost its row in the conversion');

            $this->assertStringContainsString('config[base.' . $key . ']', $html,
                $key . ' must post under the name the save controller reads');
        }
    }

    /** Three fieldsets, with the legends the page has always shown. */
    public function testTheFieldsetsKeepTheirLegendsAndOrder(): void
    {
        $sets = \OWA\Module\Base\Classes\SettingsForm::pageFieldSets('base.optionsGeneral');

        $this->assertSame(
            array('Tracking Request Processing', 'Visitor Announcements', 'Reporting'),
            array_column($sets, 'legend'),
            'the page renders its fieldsets in the order it registered them');
    }

    /** A page that names a fieldset nobody registered renders what it can. */
    public function testAnUnregisteredFieldSetIsSkippedNotRendered(): void
    {
        $this->assertSame(array(),
            \OWA\Module\Base\Classes\SettingsForm::pageFieldSets('base.noSuchSettingsPage'));
    }

    /** A boolean is Off/On, with the value in force marked. */
    public function testABooleanRendersItsCurrentValue(): void
    {
        $this->config()->registerField(self::MODULE, 'flag',
            array('default' => true, 'storable' => true, 'type' => 'boolean', 'label' => 'A Flag'));

        $html = \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'flag', 'owa_');

        $this->assertStringContainsString('<option value="0">Off</option>', $html);
        $this->assertStringContainsString('<option value="1" selected="selected">On</option>', $html,
            'the value in force must be the one shown');
    }

    /**
     * Values reach the page from the database, so they are escaped. A stored
     * quote used to end the attribute.
     */
    public function testAStoredValueIsEscaped(): void
    {
        $this->config()->registerField(self::MODULE, 'text_field',
            array('default' => '" autofocus onfocus="alert(1)', 'storable' => true,
                  'type' => 'text', 'label' => 'Text'));

        $html = \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'text_field', 'owa_');

        $this->assertStringNotContainsString('onfocus="alert', $html,
            'a stored value must not be able to close its attribute');

        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * THE BEHAVIOUR THAT CHANGED.
     *
     * A setting a config-file constant supplies cannot be saved: the constant
     * beats the stored value on every boot, so an editable field accepts an
     * edit that silently does nothing. Exactly one field said so, because
     * someone hand-wrote the check into the timezone block. The renderer does
     * it for all of them.
     */
    public function testAConstantGovernedFieldIsDisabledAndSaysWhy(): void
    {
        $this->config()->registerField(self::MODULE, 'governed',
            array('default' => 'x', 'storable' => true, 'type' => 'text', 'label' => 'Governed'));

        $this->config()->noteConfigConstant(self::MODULE, 'governed', 'OWA_ZZ_GOVERNED');

        try {
            $html = \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'governed', 'owa_');

            $this->assertStringContainsString('disabled="disabled"', $html,
                'a field whose value a constant supplies must not invite an edit');

            $this->assertStringContainsString('OWA_ZZ_GOVERNED', $html,
                'and it must name the constant, or there is nowhere to go and change it');

        } finally {

            $this->config()->forgetConfigConstant(self::MODULE, 'governed');
        }
    }

    /**
     * A governed field renders the value in force, which is a courtesy for a
     * timezone and a leak for a database password. The declaration decides,
     * and the renderer does not guess from the name.
     */
    public function testASecretIsNeverPrinted(): void
    {
        $c = $this->config();

        /*
         * A probe rather than base.db_password, because whether THAT one renders
         * at all depends on OWA_DB_PASSWORD being defined -- it is static, and a
         * static setting renders nothing unless a constant governs it. The rule
         * being tested is about the value, not about which settings appear, and
         * it has to hold in an install with no config file too.
         */
        $c->registerField(self::MODULE, 'credential', array(
            'default'  => 'hunter2-the-actual-secret',
            'storable' => true,
            'type'     => 'text',
            'label'    => 'A Credential',
            'secret'   => true,
        ));

        $html = \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'credential', 'owa_');

        $this->assertNotSame('', $html,
            'the field still renders -- what is suppressed is the value, not the row');

        $this->assertStringContainsString('value=""', $html);

        $this->assertStringNotContainsString('hunter2-the-actual-secret', $html,
            'a value declared secret reached the page');

        /*
         * And the one that matters in production is declared that way. It is
         * static and governed by OWA_DB_PASSWORD, so it should never appear on a
         * settings page -- but a governed field renders read-only by design, and
         * this is the one governed value where showing what is in force would be
         * a leak rather than a courtesy.
         */
        $this->assertTrue(
            (bool) ($c->registeredField('base', 'db_password')['secret'] ?? false),
            'base.db_password must be declared secret');
    }

    /**
     * Listing a governed setting in a fieldset is not a mistake to report.
     *
     * It is not storable, which is the same signal a STATIC setting gives --
     * and a static one genuinely is a mistake, because the form would save
     * nothing. A governed one renders read-only on purpose. Both are asserted
     * here, because the check is only worth anything if it still catches the
     * case it was written for.
     */
    public function testAGovernedSettingInAFieldSetIsNotReportedAsAProblem(): void
    {
        $c = $this->config();

        $c->registerField(self::MODULE, 'static_listed', array('default' => 'x'));
        $c->registerField(self::MODULE, 'governed_listed',
            array('default' => 'x', 'storable' => true, 'type' => 'text', 'label' => 'G'));

        $c->registerFieldSet(array(
            'id'       => self::MODULE . '.mixed',
            'module'   => self::MODULE,
            'settings' => array('static_listed', 'governed_listed'),
        ));

        $reported = fn($key) => (bool) array_filter(
            $c->fieldSetProblems(), fn($p) => str_contains($p, $key));

        $this->assertTrue($reported('static_listed'),
            'a static setting on a form saves nothing, and that is still reported');

        $this->assertFalse($reported('governed_listed'),
            'a storable setting is unremarkable before any constant governs it');

        $c->noteConfigConstant(self::MODULE, 'governed_listed', 'OWA_ZZ_LISTED');

        try {
            $this->assertFalse($c->mayPersistInstallWide(self::MODULE, 'governed_listed'),
                'the constant makes it unstorable -- the same state a static setting is in');

            $this->assertFalse($reported('governed_listed'),
                'but it renders read-only, so reporting it would name a fault that is none');

            $this->assertTrue($reported('static_listed'),
                'and the genuine mistake is still caught');

        } finally {

            $c->forgetConfigConstant(self::MODULE, 'governed_listed');
        }
    }

    /**
     * A fieldset naming a setting that cannot be saved renders NOTHING for it.
     *
     * An empty row, or a control whose save is refused, shows the mistake to
     * whoever is trying to use the screen. fieldSetProblems() reports it to the
     * operator instead, and cmd=instance-info prints that.
     */
    public function testASettingThatCannotBeSavedRendersNothing(): void
    {
        $this->config()->registerField(self::MODULE, 'static_one', array('default' => 'x'));

        $this->assertSame('',
            \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'static_one', 'owa_'),
            'a static setting has no form control: saving one is refused');

        $this->assertSame('',
            \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'never_registered', 'owa_'));
    }

    /** An unknown type is a mistake, not a text box. */
    public function testAnUnknownControlTypeRendersNothing(): void
    {
        $this->config()->registerField(self::MODULE, 'odd',
            array('default' => 'x', 'storable' => true, 'type' => 'colour_wheel', 'label' => 'Odd'));

        $this->assertSame('',
            \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'odd', 'owa_'),
            'silently downgrading to a text box hides that the author asked for '
          . 'something the renderer does not have');
    }

    /** A fieldset with nothing renderable in it renders no empty box. */
    public function testAFieldSetWithNothingToShowRendersNothing(): void
    {
        $this->config()->registerField(self::MODULE, 'static_two', array('default' => 'x'));

        $this->assertSame('', \OWA\Module\Base\Classes\SettingsForm::fieldSet(array(
            'id'       => self::MODULE . '.empty',
            'module'   => self::MODULE,
            'legend'   => 'Nothing Here',
            'settings' => array('static_two'),
        ), 'owa_'));
    }

    /** Options may be a list or a map, because a declaration should be able to
     *  say `array( 'a', 'b' )` when that is all it means. */
    public function testSelectOptionsMayBeAListOrAMap(): void
    {
        $this->config()->registerField(self::MODULE, 'listed',
            array('default' => 'b', 'storable' => true, 'type' => 'select',
                  'label' => 'L', 'options' => array('a', 'b')));

        $this->config()->registerField(self::MODULE, 'mapped',
            array('default' => 'b', 'storable' => true, 'type' => 'select',
                  'label' => 'M', 'options' => array('a' => 'Alpha', 'b' => 'Bravo')));

        $this->assertStringContainsString('<option value="b" selected="selected">b</option>',
            \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'listed', 'owa_'));

        $this->assertStringContainsString('<option value="b" selected="selected">Bravo</option>',
            \OWA\Module\Base\Classes\SettingsForm::field(self::MODULE, 'mapped', 'owa_'));
    }

    /**
     * The timezone picker is grouped by country, and a zone can appear under
     * more than one -- so marking every match would produce a select with
     * several selected options.
     */
    public function testTheTimezonePickerGroupsAndSelectsExactlyOnce(): void
    {
        $html = \OWA\Module\Base\Classes\SettingsForm::field('base', 'timezone', 'owa_');

        $this->assertGreaterThan(100, substr_count($html, '<option '),
            'the picker must offer the IANA list, not a handful');

        $this->assertStringContainsString('<optgroup label=', $html,
            'the zones are grouped by country');

        $this->assertStringContainsString(
            sprintf('<option value="%s" selected="selected"',
                \OWA\Core\CoreAPI::getSetting('base', 'timezone')),
            $html, 'the configured zone must be the selected one');

        $this->assertSame(1, substr_count($html, 'selected="selected"'));
    }

    /**
     * Thirteen IANA zones in conf/country2Timezones.php are listed under more
     * than one country, so marking every match would produce a select with
     * several selected options. Whichever the browser then honours, the page is
     * no longer showing the operator what is configured.
     *
     * The zone this uses is asserted to be one of the repeated ones, because
     * the whole test is vacuous against a zone that appears once. The default
     * on this install is America/Los_Angeles, which does.
     */
    public function testAZoneListedUnderSeveralCountriesSelectsOnce(): void
    {
        /** @var array<string, array<string>> $timezones */
        require(OWA_DIR . 'conf/country2Timezones.php');

        $countries = 0;

        foreach ($timezones as $zones) {

            if (in_array('Europe/Paris', (array) $zones, true)) {
                $countries++;
            }
        }

        $this->assertGreaterThan(1, $countries,
            'Europe/Paris must be listed under several countries or this proves nothing');

        $c = \OWA\Core\CoreAPI::configSingleton();

        $was = $c->get('base', 'timezone');

        try {
            $c->set('base', 'timezone', 'Europe/Paris');

            $html = \OWA\Module\Base\Classes\SettingsForm::field('base', 'timezone', 'owa_');

            $this->assertSame(1, substr_count($html, 'selected="selected"'),
                sprintf('Europe/Paris is listed under %d countries and was marked '
                      . 'selected in each', $countries));

            $this->assertStringContainsString('<option value="Europe/Paris" selected="selected"',
                $html);

        } finally {

            $c->set('base', 'timezone', $was);
        }
    }

    /**
     * The templates must not go back to writing controls by hand. Asserted
     * against the source, because a hand-written field renders perfectly well
     * -- it just stops agreeing with the registry.
     */
    public function testTheConvertedTemplatesDoNotHandWriteControls(): void
    {
        foreach (array(
            'modules/Base/templates/options_general.php',
            'modules/MaxmindGeoip/templates/options_geoip.php',
        ) as $template) {

            /*
             * The CODE, not the prose. Both templates explain in a comment what
             * they used to do, and `config[` appears in that explanation.
             */
            $code = php_strip_whitespace(OWA_DIR . $template);

            $this->assertStringNotContainsString('config[', $code,
                $template . ' assembles a submit name by hand; the renderer knows it');

            $src = (string) file_get_contents(OWA_DIR . $template);

            $this->assertStringContainsString('SettingsForm::fieldSet', $src,
                $template . ' must build its fields from the registry');
        }
    }

    /** The GeoIP page converted too, and kept both of its fields. */
    public function testTheGeoipPageRendersItsTwoSettings(): void
    {
        $sets = owa_test_page_fieldsets(
            'maxmind_geoip.optionsGeoip', \OWA\Module\MaxmindGeoip\Module::class );

        $this->assertCount(1, $sets);

        $html = \OWA\Module\Base\Classes\SettingsForm::fieldSet($sets[0], 'owa_');

        $this->assertStringContainsString('config[maxmind_geoip.db_license_key]', $html);
        $this->assertStringContainsString('config[maxmind_geoip.db_edition]', $html);

        $this->assertStringContainsString('GeoLite2-Country', $html,
            'the edition list comes from the declaration now, not from the view');
    }
}
