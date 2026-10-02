<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\SettingsForm;

/**
 * The attribution lookback is a whole number of days from 0 to 365.
 *
 * The limit is the declaration's (`integer`, `min`, `max`), and both write
 * chokepoints hold every caller to it: persistSetting() for the install,
 * CoreAPI::setScopedSetting() for a Property. The forms report it.
 */
final class AttributionLookbackCapTest extends TestCase
{
    private const PROPERTY = 'zz-lookback-cap-property';

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::clearScopedSetting('property', self::PROPERTY, 'base', 'attribution_lookback_days');
        }
    }

    /** @return string[] the messages a controller's validations produce */
    private function errors(\OWA\Core\Controller $c): array
    {
        $v = (new ReflectionProperty(\OWA\Core\Controller::class, 'v'))->getValue($c);

        if (!$v) {
            return array();
        }

        $v->doValidations();

        return array_values((array) $c->getValidationErrorMsgs());
    }

    private function config()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    public function testTheDeclarationStatesTheLimit(): void
    {
        $args = $this->config()->registeredField('base', 'attribution_lookback_days');

        $this->assertSame('integer', $args['type']);
        $this->assertSame(0, $args['min']);
        $this->assertSame(365, $args['max']);
    }

    public function testValuesInsideTheLimitPass(): void
    {
        foreach (array(0, 1, 90, 365, '30', ' 45 ') as $value) {
            $this->assertNull($this->config()->valueProblem('base', 'attribution_lookback_days', $value),
                var_export($value, true));
        }
    }

    public function testValuesOutsideItAreRefusedWithTheRule(): void
    {
        foreach (array(366, 1000, -1, '12.5', 'abc', '') as $value) {
            $this->assertSame('Attribution lookback (days) is a whole number from 0 to 365.',
                $this->config()->valueProblem('base', 'attribution_lookback_days', $value),
                var_export($value, true));
        }
    }

    /** The install's chokepoint: a refused value never reaches the setting. */
    public function testTheInstallWriteRefusesIt(): void
    {
        $c      = $this->config();
        $before = $c->get('base', 'attribution_lookback_days');

        $c->persistSetting('base', 'attribution_lookback_days', 1000);

        $this->assertSame($before, $c->get('base', 'attribution_lookback_days'));
    }

    /** A Property's chokepoint: refused, and nothing stored. In range, stored as a number. */
    public function testThePropertyWriteRefusesItAndStoresANumber(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes a scoped setting row');
        }

        $this->assertFalse(\OWA\Core\CoreAPI::setScopedSetting(
            'property', self::PROPERTY, 'base', 'attribution_lookback_days', 1000));
        $this->assertNull(\OWA\Core\CoreAPI::getScopedSettingRow(
            'property', self::PROPERTY, 'base', 'attribution_lookback_days'));

        $this->assertTrue((bool) \OWA\Core\CoreAPI::setScopedSetting(
            'property', self::PROPERTY, 'base', 'attribution_lookback_days', '30'));
        $this->assertSame(30, \OWA\Core\CoreAPI::getScopedSettingRow(
            'property', self::PROPERTY, 'base', 'attribution_lookback_days'));
    }

    public function testThePropertyScreenRendersANumberField(): void
    {
        $html = SettingsForm::scopedField('base', 'attribution_lookback_days', 'property', '', 'owa_');

        $this->assertStringContainsString(
            '<input type="number" step="1" min="0" max="365" name="owa_config[base.attribution_lookback_days]"', $html);
    }

    /** The Property save reports the refusal on the form. */
    public function testThePropertyFormReportsIt(): void
    {
        $c = new \OWA\Module\Base\Controller\PropertyEdit(array(
            'name'       => 'Lookback cap',
            'domain'     => 'example.test',
            'config'     => array('base.attribution_lookback_days' => '400'),
            'override'   => array('base.attribution_lookback_days' => '1'),
        ));

        $this->assertContains('Attribution lookback (days) is a whole number from 0 to 365.', $this->errors($c));
    }

    /** ...and the install's form does too. */
    public function testTheInstallFormReportsIt(): void
    {
        $c = new \OWA\Module\Base\Controller\OptionsUpdate(array(
            'config' => array('base.attribution_lookback_days' => '400'),
        ));

        $this->assertContains('Attribution lookback (days) is a whole number from 0 to 365.', $this->errors($c));
    }
}
