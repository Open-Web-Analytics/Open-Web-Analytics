<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/SettingsSingletonSnapshot.php';

/** OptionsUpdate with no validation of its own, so every posted value reaches persistSetting(). */
class PersistSettingAnswerOptionsUpdate extends \OWA\Module\Base\Controller\OptionsUpdate
{
    public function validate()
    {
    }
}

/**
 * Settings::persistSetting() answers whether it took the value, and the
 * options form lists as saved only the settings it took.
 *
 * It answered null whether it stored the value or refused it, and the form
 * counted anything but false as saved, so a refused setting was announced in
 * base.install_settings_saved.
 */
final class PersistSettingAnswerTest extends TestCase
{
    use SettingsSingletonSnapshot;

    /** @var list<array{module: string, keys: array}> */
    public static $heard = array();

    /** @var bool */
    public static $listening = false;

    /** @var bool */
    private static $attached = false;

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        self::$listening = false;
        $this->restoreSettings();
    }

    public function testAValueItStoresAnswersTrue(): void
    {
        $this->assertTrue($this->settings()->persistSetting('base', 'attribution_lookback_days', 30));
        $this->assertSame(30, $this->settings()->get('base', 'attribution_lookback_days'));
    }

    /** Equal to the default: not stored, and the setting has the value, so it is taken. */
    public function testTheDefaultAnswersTrue(): void
    {
        $this->assertTrue($this->settings()->persistSetting('base', 'attribution_lookback_days', 90));
    }

    public function testAValueItsDeclarationRefusesAnswersFalse(): void
    {
        $this->assertFalse($this->settings()->persistSetting('base', 'attribution_lookback_days', 1000));
    }

    public function testASettingNoModuleDeclaresAnswersFalse(): void
    {
        $this->assertFalse($this->settings()->persistSetting('base', 'zz_no_such_setting', 'x'));
    }

    /** The form's saved list, as base.install_settings_saved hears it. */
    public function testTheFormAnnouncesOnlyWhatWasTaken(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('The form saves the settings to the database.');
        }

        if (!self::$attached) {
            \OWA\Core\CoreAPI::getEventDispatch()->attach('base.install_settings_saved', function ($event) {
                if (PersistSettingAnswerTest::$listening) {
                    PersistSettingAnswerTest::$heard[] = array(
                        'module' => (string) $event->get('module'),
                        'keys'   => (array) $event->get('keys'),
                    );
                }

                return OWA_EHS_EVENT_HANDLED;
            });
            self::$attached = true;
        }

        $c = new PersistSettingAnswerOptionsUpdate(array(
            'config' => array(
                'base.attribution_lookback_days' => '90',
                'base.currencyISO3'              => 'US',
            ),
        ));

        self::$heard     = array();
        self::$listening = true;
        $c->action();
        self::$listening = false;

        $this->assertSame(array(array('module' => 'base', 'keys' => array('attribution_lookback_days'))), self::$heard);
    }
}
