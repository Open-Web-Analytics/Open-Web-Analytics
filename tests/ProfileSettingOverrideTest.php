<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\SitesEditSettings;

/**
 * Saving a Profile's settings must not turn inherited values into overrides.
 *
 * The screen renders EFFECTIVE values on purpose -- a key the Profile does not
 * set shows the Property's, Organization's or install's value, because that is
 * what the Profile will actually observe with. The consequence is that every
 * save posts the whole form back, inherited values included.
 *
 * Writing each one as a scoped row meant that opening the screen and pressing
 * Save without changing anything detached that Profile from everything above
 * it, for every key on the form. Silently: the screen looks identical
 * afterwards, because an override that equals the inherited value renders the
 * same. And permanently, from the UI's point of view -- clearScopedSetting()
 * exists for exactly this and no screen called it.
 *
 * overrideAction() is the decision, kept pure so it can be asserted without a
 * database. Its three answers are the three things a save can mean.
 */
final class ProfileSettingOverrideTest extends TestCase
{
    public function testAValueThatDiffersFromTheInheritedOneIsWritten(): void
    {
        $this->assertSame('set', SitesEditSettings::overrideAction('90', '30', false));
        $this->assertSame('set', SitesEditSettings::overrideAction('90', '30', true));
    }

    /**
     * The reported bug: open, change nothing, save.
     */
    public function testAnUnchangedInheritedValueWritesNothing(): void
    {
        $this->assertSame('none', SitesEditSettings::overrideAction('30', '30', false),
            'posting back the value the Profile already inherits must not pin it');
    }

    /**
     * The way back. Setting a Profile's value to what it would inherit removes
     * the override, so it follows the tier above again.
     */
    public function testMatchingTheInheritedValueClearsAnExistingOverride(): void
    {
        $this->assertSame('clear', SitesEditSettings::overrideAction('30', '30', true));
    }

    /**
     * A form posts strings against stored ints and bools, so the comparison
     * cannot be ===. These are the shapes the settings forms actually send.
     *
     * @dataProvider equivalentValues
     */
    public function testValuesThatMeanTheSameThingAreNotWrittenAsOverrides($posted, $inherited): void
    {
        $this->assertSame('none', SitesEditSettings::overrideAction($posted, $inherited, false),
            var_export($posted, true) . ' and ' . var_export($inherited, true) . ' mean the same thing');
    }

    public static function equivalentValues(): array
    {
        return array(
            'select off vs stored false' => array('0', false),
            'select on vs stored true'   => array('1', true),
            'number vs stored int'       => array('30', 30),
            'identical strings'          => array('abc', 'abc'),
        );
    }

    /**
     * ...but not so loose that two different strings collide. '1e2' == '100'
     * is true in PHP for two numeric strings, which is why the comparison is
     * === between strings.
     *
     * @dataProvider differingValues
     */
    public function testValuesThatDifferAreWritten($posted, $inherited): void
    {
        $this->assertSame('set', SitesEditSettings::overrideAction($posted, $inherited, false));
    }

    public static function differingValues(): array
    {
        return array(
            'numeric strings that are not the same string' => array('1e2', '100'),
            'off against a stored on'                      => array('0', true),
            'different numbers'                            => array('90', 30),
            'empty against a set value'                    => array('', 'daily'),
        );
    }
}
