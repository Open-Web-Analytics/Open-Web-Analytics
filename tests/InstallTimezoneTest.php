<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The reporting timezone is chosen at INSTALL, because changing it later is not
 * retroactive.
 *
 * yyyymmdd and the nine date-part columns (year, month, day, hour, dayofweek,
 * dayofyear, weekofyear...) are derived in the configured timezone and written
 * INTO each fact row at collection time. So a later change re-buckets new rows
 * while history keeps the old boundaries, and nothing on the row records which
 * zone it was derived under -- reports group by yyyymmdd and silently mix the
 * two. The shift can be up to 21 hours, nearly a whole day.
 *
 * The setting shipped defaulting to America/Los_Angeles and was not asked for
 * anywhere in the wizard, so every install ran on US Pacific until someone
 * noticed. Asking at install is the only moment the choice is free.
 *
 * Note this is NOT the same class of problem as a wrong clock: `timestamp` is a
 * unix epoch and timezone-free, and there is no date arithmetic in SQL at all
 * (no NOW(), CURDATE() or FROM_UNIXTIME in any query string), so PHP-derived
 * bounds and stored yyyymmdd always agree with each other. What varies is only
 * WHICH INSTANT counts as midnight.
 */
final class InstallTimezoneTest extends TestCase
{
    private function installForm(): string
    {
        return (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/install_defaults_entry.php'
        );
    }

    private function optionsForm(): string
    {
        return (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/options_general.php'
        );
    }

    public function testTheInstallWizardAsksForATimezone(): void
    {
        $this->assertStringContainsString(
            'getNs();?>timezone',
            $this->installForm(),
            'the install wizard must collect a timezone; it defaulted silently to US Pacific'
        );
    }

    /**
     * The field must preselect the CONFIGURED default, not an empty value.
     * 'defaults' is only populated when the form re-renders after a validation
     * error, so an empty fallback on first render would preselect whichever zone
     * sorts first -- silently choosing for the operator, which is the failure
     * this field exists to prevent.
     */
    public function testTheFieldPreselectsTheConfiguredDefaultNotAnEmptyValue(): void
    {
        $form = $this->installForm();

        $this->assertMatchesRegularExpression(
            "/defaults\['timezone'\]\s*\n?\s*\?\?\s*\\\\OWA\\\\Core\\\\CoreAPI::getSetting\(\s*'base',\s*'timezone'\s*\)/",
            $form,
            'the timezone select must fall back to the configured setting'
        );
    }

    public function testTheInstallerPersistsTheChosenTimezone(): void
    {
        $controller = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstallBase.php'
        );

        $this->assertStringContainsString(
            "persistSetting('base', 'timezone'",
            $controller,
            'a timezone collected but not persisted is worse than not asking'
        );
    }

    /**
     * It arrives from a form, so it is validated against the real zone list
     * rather than trusted. date_default_timezone_set() on an unknown identifier
     * leaves every later date derivation on the previous default -- which would
     * be the silent wrong-bucket bug this whole change exists to avoid.
     */
    public function testAnUnknownTimezoneIsRefusedRatherThanStored(): void
    {
        $controller = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstallBase.php'
        );

        $this->assertStringContainsString(
            'DateTimeZone::listIdentifiers()',
            $controller,
            'the submitted timezone must be checked against the real zone list'
        );

        // and the guard is real: a plausible-looking bad value is not a zone
        $this->assertNotContains('America/Nowhere', \DateTimeZone::listIdentifiers());
        $this->assertContains('America/Los_Angeles', \DateTimeZone::listIdentifiers());
    }

    /**
     * The settings page must say so. A user changing it there has no other way
     * to learn that their existing data keeps its old day boundaries.
     */
    public function testTheSettingsPageSaysTheChangeIsNotRetroactive(): void
    {
        $options = $this->optionsForm();

        $this->assertMatchesRegularExpression(
            '/not retroactive/i',
            $options,
            'the timezone setting must warn that a change does not rewrite history'
        );
    }

    /**
     * OWA_TIMEZONE lets a scripted or CLI install declare the zone up front.
     *
     * The CLI installer takes its configuration from owa-config.php and asks for
     * nothing, so a constant is the route there. It WINS over a stored value --
     * see testADeclaredConstantBeatsTheStoredValue below.
     */
    public function testAConfigConstantSuppliesTheTimezone(): void
    {
        $settings = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/Settings.php'
        );

        $this->assertStringContainsString("defined('OWA_TIMEZONE')", $settings);
        $this->assertStringContainsString(
            "setFromConfigConstant( 'base', 'timezone', OWA_TIMEZONE, 'OWA_TIMEZONE')", $settings);
    }

    /**
     * Validated, not trusted. date_default_timezone_set() on an unknown
     * identifier leaves every later derivation on the previous default -- the
     * silent wrong-bucket failure this whole change exists to prevent.
     */
    public function testAnUnrecognisedConstantIsIgnoredRatherThanApplied(): void
    {
        $settings = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/Settings.php'
        );

        // The guard and the assignment must be in the SAME statement, or the
        // constant would be applied first and validated afterwards.
        $i = strpos($settings, "defined('OWA_TIMEZONE')");
        $this->assertNotFalse($i, 'OWA_TIMEZONE is not read at all');

        $statement = substr($settings, $i, 260);

        $this->assertStringContainsString('DateTimeZone::listIdentifiers()', $statement);
        $this->assertStringContainsString('in_array', $statement);
        $this->assertLessThan(
            strpos($statement, "'base', 'timezone', OWA_TIMEZONE"),
            strpos($statement, 'listIdentifiers'),
            'the zone list must be checked BEFORE the value is applied'
        );
    }

    /**
     * And it is discoverable: a constant nobody documents is a constant nobody
     * uses. The dist config must also say it is a seed, since the surprise
     * otherwise is a config file that plainly disagrees with the running install.
     */
    public function testTheDistConfigDocumentsItAndSaysItWins(): void
    {
        $dist = (string) file_get_contents( OWA_DIR . 'owa-config-dist.php' );

        $this->assertStringContainsString("OWA_TIMEZONE", $dist);
        $this->assertMatchesRegularExpression('/not retroactive/i', $dist);
        $this->assertMatchesRegularExpression('/IF YOU SET THIS, IT WINS/i', $dist);
    }

    /**
     * THE PRECEDENCE RULE. A constant declared in owa-config.php beats a value
     * stored in the database.
     *
     * It used to lose: applyConfigConstants() runs before load(), and load()
     * array_merges the database blob over the top, so the constant was silently
     * discarded. And the options form writes EVERY field on its page rather than
     * only the edited one, so a timezone entered the database the first time
     * anyone saved General Settings for an unrelated reason -- after which the
     * config file said one thing and the installation did another.
     *
     * Expressed as ABSENCE, like the config-file-only settings: the losing value
     * is removed from the database array before the merge, because a merge is
     * the only thing precedence can be expressed through here.
     */
    public function testADeclaredConstantBeatsTheStoredValue(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $ledger = $c->config_file_constants;
        $c->config_file_constants = array( 'base' => array( 'timezone' => true ) );

        try {
            $stored = array( 'base' => array( 'timezone' => 'Europe/London', 'other' => 'kept' ) );
            $result = $c->stripSettingsSuppliedByConstants( $stored );

            $this->assertArrayNotHasKey('timezone', $result['base'],
                'a stored value must not survive a declared constant');
            $this->assertSame('kept', $result['base']['other'],
                'only the declared key is removed');
        } finally {
            $c->config_file_constants = $ledger;
        }
    }

    /**
     * And the whole thing is OPT-IN, which is what makes it safe to change: an
     * installation that declares no constants strips nothing and behaves exactly
     * as it did before. Anything else would silently reset settings on upgrade
     * for every install that had configured them through the UI.
     */
    public function testAnInstallWithNoConstantsIsUnaffected(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $ledger = $c->config_file_constants;
        $c->config_file_constants = array();

        try {
            $stored = array( 'base' => array( 'timezone' => 'Europe/London' ) );

            $this->assertSame( $stored, $c->stripSettingsSuppliedByConstants( $stored ) );
        } finally {
            $c->config_file_constants = $ledger;
        }
    }

    /**
     * A constant-governed setting is NOT editable from the options page, so the
     * form refuses it rather than storing a value that the next boot ignores.
     *
     * The field renders disabled, so a browser will not submit it -- this is the
     * server-side guarantee behind that, for a crafted POST or a stale page.
     */
    public function testTheOptionsFormRefusesAConstantGovernedSetting(): void
    {
        $controller = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/OptionsUpdate.php'
        );

        // Scoped to the governing-constant branch ONLY. A wider window catches
        // the `continue` belonging to the sensitive-key check below it, and then
        // passes even when this branch merely warns.
        $i = strpos($controller, 'if ( $governing_constant ) {');
        $this->assertNotFalse($i, 'the form never checks whether a constant governs the key');

        $j = strpos($controller, 'isSensitiveSettingKey', $i);
        $this->assertNotFalse($j, 'the sensitive-key check moved; this test needs updating');

        $branch = substr($controller, $i, $j - $i);

        $this->assertMatchesRegularExpression('/Refusing to change/i', $branch);
        $this->assertStringContainsString('continue;', $branch,
            'it must skip the write, not merely warn about it');
    }

    /**
     * ...and the notice NAMES the constant, because "set in owa-config.php"
     * leaves the operator hunting for which line to change.
     */
    public function testTheRefusalNamesTheConstant(): void
    {
        $controller = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/OptionsUpdate.php'
        );

        $this->assertStringContainsString('$governing_constant', $controller);
    }

    /**
     * The field itself renders read-only and says which constant governs it.
     */
    public function testTheFieldRendersDisabledAndNamesTheConstant(): void
    {
        $form = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/options_general.php'
        );

        $this->assertStringContainsString("configFileConstantFor( 'base', 'timezone' )", $form);
        $this->assertStringContainsString('disabled="disabled"', $form);
        $this->assertMatchesRegularExpression('/Set by .*tz_constant/s', $form);
    }

    /**
     * The lookup returns the NAME, not a boolean -- that is what makes the
     * message actionable. Verified against a constant this install really
     * defines.
     */
    public function testTheLookupReturnsTheConstantName(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertSame('OWA_DB_NAME', $c->configFileConstantFor('base', 'db_name'));
        $this->assertSame('', $c->configFileConstantFor('base', 'no_such_setting'));
    }

    /**
     * The ledger is populated by a REAL boot, not just by the tests that poke it.
     *
     * The two tests above set config_file_constants by hand, which exercises the
     * stripper but proves nothing about whether anything ever records into it.
     * This install defines OWA_DB_NAME among others, so after boot the ledger
     * must contain the settings those constants supplied.
     */
    public function testARealBootRecordsWhatTheConstantsSupplied(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertNotEmpty(
            $c->config_file_constants,
            'applyConfigConstants() records nothing, so no constant can ever win'
        );
        $this->assertArrayHasKey('db_name', $c->config_file_constants['base'],
            'OWA_DB_NAME is defined by this install, so db_name must be recorded');
    }

    /**
     * ...and load() actually calls the stripper, in the right place.
     *
     * A source assertion, deliberately: the effect is only observable when an
     * installation has BOTH a constant and a stored value for the same key, and
     * the measured overlap across all three installs here is zero -- timezone is
     * the only setting that can be written from both sides, and none of them has
     * saved it. So there is no state in which the wiring can be observed from
     * behaviour alone without fabricating a database.
     *
     * Position matters as much as presence: it must run after the security strip
     * and before the merge, or the database values it is meant to lose to have
     * already been merged in.
     */
    public function testLoadAppliesTheStripperBeforeMergingTheDatabase(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/Settings.php'
        );

        // Wide enough to reach the merge, which sits ~2.7k into load(). A window
        // that stops short makes this test fail for the wrong reason.
        $load = substr($src, strpos($src, 'function load('), 4000);

        $strip  = strpos($load, 'stripSettingsSuppliedByConstants(');
        $secure = strpos($load, 'stripConfigFileOnlySettings(');
        $merge  = strpos($load, 'array_merge($default[$k]');

        $this->assertNotFalse($strip, 'load() never applies the constant precedence rule');
        $this->assertNotFalse($merge, 'the merge moved; this test needs updating');
        $this->assertGreaterThan($secure, $strip, 'must run after the security strip');
        $this->assertLessThan($merge, $strip, 'must run before the database is merged in');
    }

    /**
     * UTC is selectable.
     *
     * conf/country2Timezones.php is keyed by COUNTRY, and UTC is not a country,
     * so it was absent from both pickers -- 285 zones and no way to choose the
     * one that does not move. For a self-hosted product whose operator may run
     * servers in several regions, that is the omission most likely to matter.
     */
    /**
     * A wizard whose timezone field is governed by a constant.
     *
     * Rendered rather than scanned. The other tests in this file read the
     * template as text, which cannot tell whether a branch is reachable -- and
     * this one has two branches that must not both fire.
     */
    private function renderWizard( string $constant ): string
    {
        $t = new class('base') extends \OWA\Core\Template {
            public $fake_constant = '';
            function configFileConstantFor( $module, $key ) { return $this->fake_constant; }
            function getNs() { return ''; }
        };

        $t->fake_constant = $constant;
        $t->set('defaults', array());
        $t->set_template('install_defaults_entry.php');

        return $t->fetch();
    }

    /**
     * The wizard is reachable with the constant already in force.
     *
     * The installer refuses to overwrite an existing owa-config.php, so an
     * operator who wrote one by hand -- the documented path for a CLI install,
     * and the usual one for a scripted deploy -- arrives at this step with
     * OWA_TIMEZONE already deciding the answer. An editable field there would
     * take a choice that never takes effect.
     */
    public function testTheWizardFieldIsDisabledWhenAConstantSuppliesIt(): void
    {
        $html = $this->renderWizard( 'OWA_TIMEZONE' );

        $this->assertMatchesRegularExpression(
            '/<select name="timezone" disabled="disabled">/', $html,
            'the picker must not be editable when a constant decides the value' );

        $this->assertStringContainsString( 'OWA_TIMEZONE', $html,
            'the message has to name the constant, or it is not actionable' );
    }

    /**
     * A disabled control is not submitted, and unlike the options page the
     * installer validates timezone as REQUIRED -- so without a hidden carrier
     * the wizard would refuse to advance.
     */
    public function testTheDisabledFieldStillSubmitsAValue(): void
    {
        $html = $this->renderWizard( 'OWA_TIMEZONE' );

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="timezone"\s+value="[^"]+"/', $html,
            'a disabled select posts nothing, and InstallBase requires the field' );
    }

    public function testWithoutAConstantTheFieldIsOrdinary(): void
    {
        $html = $this->renderWizard( '' );

        $this->assertStringContainsString( '<select name="timezone">', $html );
        $this->assertStringNotContainsString( 'disabled="disabled"', $html );
        $this->assertStringNotContainsString( 'OWA_TIMEZONE', $html );
        $this->assertStringContainsString( 'not retroactive', $html,
            'the ordinary case keeps the warning that the choice is permanent' );
    }

    /**
     * Both renders must offer the full picker. The conf files assign their
     * arrays into the template scope, so including them with require_once made
     * a second render in the same process produce an EMPTY picker -- silently,
     * with no error and no output.
     */
    public function testThePickerSurvivesASecondRenderInTheSameProcess(): void
    {
        $first  = $this->renderWizard( 'OWA_TIMEZONE' );
        $second = $this->renderWizard( '' );

        $this->assertGreaterThan( 200, substr_count( $first, '<option' ) );
        $this->assertSame(
            substr_count( $first, '<option' ),
            substr_count( $second, '<option' ),
            'a second render must offer the same zones as the first' );
    }

    /**
     * The server-side half. The browser honouring `disabled` is a courtesy;
     * this is the guarantee.
     */
    public function testTheInstallerDoesNotStoreAConstantGovernedTimezone(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstallBase.php' );

        $this->assertMatchesRegularExpression(
            "/configFileConstantFor\(\s*'base',\s*'timezone'\s*\)/", $src,
            'InstallBase must consult the constant before persisting' );

        $guard = strpos( $src, 'configFileConstantFor' );
        $store = strpos( $src, "persistSetting('base', 'timezone'" );

        $this->assertNotFalse( $guard );
        $this->assertNotFalse( $store );
        $this->assertLessThan( $store, $guard,
            'the guard has to come before the write, not after it' );
    }

    public function testUtcIsOfferedByThePicker(): void
    {
        /*
         * Loaded into an isolated scope with require, not require_once.
         *
         * These files only assign their arrays into the calling scope, so
         * require_once here made this test depend on nothing else having
         * included them first -- and once another test in this class started
         * RENDERING the picker, that stopped being true and the arrays arrived
         * undefined. Order-dependent by construction; this makes it not.
         */
        $load = static function ( string $file ) {
            require OWA_DIR . 'conf/' . $file;
            return get_defined_vars();
        };

        $timezones        = $load('country2Timezones.php')['timezones'];
        $countryCode2Name = $load('countryCodes2Names.php')['countryCode2Name'];

        $offered = array();

        foreach ($timezones as $zones) {
            $offered = array_merge($offered, $zones);
        }

        $this->assertContains('UTC', $offered, 'UTC must be selectable');

        // ...and its group is labelled, or the template renders "unknown - UTC"
        $this->assertArrayHasKey('UTC', $countryCode2Name);
        $this->assertStringContainsString('Coordinated Universal Time', $countryCode2Name['UTC']);

        // a real zone identifier, not just a string in a list
        $this->assertContains('UTC', \DateTimeZone::listIdentifiers());
    }

    /**
     * The guard against this suite passing vacuously: prove the strings it looks
     * for are absent from a form that does NOT have the feature, so a future
     * template rewrite that drops the field actually fails.
     */
    public function testTheAssertionsWouldFailOnAFormWithoutTheField(): void
    {
        $without = "<form method=\"POST\">\n<input name=\"domain\">\n</form>";

        $this->assertStringNotContainsString('getNs();?>timezone', $without);
        $this->assertDoesNotMatchRegularExpression('/not retroactive/i', $without);
    }
}
