<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\SettingsForm;

/**
 * The Property's Details screen offers the currency its revenue is reported
 * in, read at Property scope (ResultSetManager), and its campaign parameter
 * names, beside the utm_ switch.
 */
final class PropertySettingsScreenTest extends TestCase
{
    private const PROPERTY = 'zz-property-settings-screen';

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            foreach (array('currencyISO3', 'campaignKeys') as $key) {
                \OWA\Core\CoreAPI::clearScopedSetting('property', self::PROPERTY, 'base', $key);
            }
        }
    }

    /** @return array[] the fieldsets PropertyEdit validates and saves */
    private function sets(): array
    {
        $m = new ReflectionMethod(\OWA\Module\Base\Controller\PropertyEdit::class, 'scopedFieldSets');
        $m->setAccessible(true);

        return (array) $m->invoke(null);
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

    /** For a Property not yet saved, each field shows the install's value: no database. */
    public function testTheScreenOffersTheCurrencyAndTheCampaignParameterNames(): void
    {
        $html = '';

        foreach ($this->sets() as $set) {
            $html .= SettingsForm::scopedFieldSet($set, 'property', '', 'owa_');
        }

        $this->assertStringContainsString('<input type="text" size="50" name="owa_config[base.currencyISO3]"', $html);
        $this->assertStringContainsString('<input type="text" size="50" name="owa_config[base.campaignKeys]"', $html);
        $this->assertStringContainsString('name="owa_config[base.enableEcommerceReporting]"', $html);
    }

    public function testTheCurrencyBesideECommerceAndTheNamesBesideTheUtmSwitch(): void
    {
        $this->assertSame(array('enableEcommerceReporting', 'currencyISO3'),
            SettingsForm::registeredFieldSet('base.propertyReporting')['settings'] ?? null);
        $this->assertSame(array('attribution_lookback_days', 'campaignUtmParams', 'campaignKeys'),
            SettingsForm::registeredFieldSet('base.propertyAttribution')['settings'] ?? null);
    }

    /** A currency is a three-letter code; anything else is refused on the form. */
    public function testThePropertyFormReportsACurrencyThatIsNotACode(): void
    {
        $this->assertNull(\OWA\Core\CoreAPI::configSingleton()->valueProblem('base', 'currencyISO3', 'EUR'));

        $c = new \OWA\Module\Base\Controller\PropertyEdit(array(
            'name'     => 'Currency code',
            'domain'   => 'example.test',
            'config'   => array('base.currencyISO3' => 'dollars'),
            'override' => array('base.currencyISO3' => '1'),
        ));

        $this->assertContains('Currency is a three-letter ISO 4217 code, such as USD.', $this->errors($c));
    }

    /** Both save at the Property through the screen's fieldsets. */
    public function testBothAreSavedAtTheProperty(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes scoped setting rows');
        }

        $config = array('base.currencyISO3' => 'EUR', 'base.campaignKeys' => 'source=src, campaign=promo');
        $override = array('base.currencyISO3' => '1', 'base.campaignKeys' => '1');

        foreach ($this->sets() as $set) {
            $this->assertTrue(SettingsForm::saveScoped($set, 'property', self::PROPERTY, $config, $override));
        }

        $this->assertSame('EUR',
            \OWA\Core\CoreAPI::getScopedSettingRow('property', self::PROPERTY, 'base', 'currencyISO3'));
        $this->assertSame(array('source' => 'src', 'campaign' => 'promo'),
            \OWA\Core\CoreAPI::getScopedSettingRow('property', self::PROPERTY, 'base', 'campaignKeys'));

        $this->assertSame('EUR', \OWA\Core\CoreAPI::getSetting('base', 'currencyISO3', 'property', self::PROPERTY),
            'the reader resolves the Property\'s value');
    }
}
