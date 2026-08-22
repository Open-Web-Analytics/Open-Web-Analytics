<?php

use PHPUnit\Framework\TestCase;

/**
 * Saving settings returns to the page that was being edited.
 *
 * The shared save controller is the action every settings form in OWA posts to,
 * so on its own it can only send everyone to the same place -- right for the
 * general settings page the form came from, wrong for every other one. Saving
 * the GeoIP settings landed the administrator on the general settings page,
 * with "Options Saved." attached to a page they had not been editing.
 *
 * A page that wants to be returned to subclasses the save controller and says
 * so. The destination is therefore a fact about the code rather than a value
 * posted by the browser: nothing to validate, nothing to tamper with, and the
 * same shape as every other controller in OWA, each of which passes a literal
 * to setRedirectAction().
 */
final class OptionsReturnActionTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function returnActionOf( $class ) {

        $method = new ReflectionMethod( $class, 'returnAction' );
        $method->setAccessible( true );

        return $method->invoke( ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor() );
    }

    /**
     * Every form that existed before this change posts to the shared
     * controller and must keep landing exactly where it did.
     */
    public function testTheSharedControllerStillReturnsToGeneralSettings(): void {

        $this->assertSame( 'base.optionsGeneral',
            $this->returnActionOf( \OWA\Module\Base\Controller\OptionsUpdate::class ) );
    }

    public function testTheGeoipSaveReturnsToTheGeoipPage(): void {

        $this->assertSame( 'maxmind_geoip.optionsGeoip',
            $this->returnActionOf( \OWA\Module\MaxmindGeoip\Controller\OptionsGeoipUpdate::class ),
            'the administrator must land back on the page they were editing' );
    }

    /**
     * It inherits the saving, so it inherits the protection on which settings a
     * web form may write -- a subclass that reimplemented action() would lose
     * that silently.
     */
    public function testTheGeoipSaveInheritsTheSharedSavingLogic(): void {

        $subclass = new ReflectionClass( \OWA\Module\MaxmindGeoip\Controller\OptionsGeoipUpdate::class );

        $this->assertTrue(
            $subclass->isSubclassOf( \OWA\Module\Base\Controller\OptionsUpdate::class ) );

        $this->assertSame(
            \OWA\Module\Base\Controller\OptionsUpdate::class,
            $subclass->getMethod( 'action' )->getDeclaringClass()->getName(),
            'action() must still be the shared one, including its restricted-settings check' );
    }

    /**
     * That action() actually consults returnAction(), which is a separate claim
     * from returnAction() returning the right thing. Reverting the redirect to
     * a fixed page passes every other test here -- they call the helper
     * directly and never go through the method that redirects.
     */
    public function testTheSaveActuallyRedirectsWhereReturnActionSays(): void
    {
        $controller = new class extends \OWA\Module\Base\Controller\OptionsUpdate {

            public function __construct() {}

            protected function returnAction() {

                return 'sentinel.returnActionWasConsulted';
            }
        };

        $data = new ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );
        $data->setValue( $controller, [] );

        $params = new ReflectionProperty( \OWA\Core\Controller::class, 'params' );
        $params->setAccessible( true );
        $params->setValue( $controller, [] );

        $controller->action();

        $this->assertSame( 'sentinel.returnActionWasConsulted', $data->getValue( $controller )['do'] ?? null,
            'action() must redirect to what returnAction() decided, not to a fixed page' );
    }

    /**
     * The form has to post to the action that knows where to return, and its
     * nonce has to be for that same action or the save is rejected.
     */
    public function testTheGeoipFormPostsToItsOwnSaveAction(): void {

        $template = (string) file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/templates/options_geoip.php' );

        $this->assertStringContainsString( 'value="maxmind_geoip.optionsGeoipUpdate"', $template );
        $this->assertStringContainsString( "createNonceFormField('maxmind_geoip.optionsGeoipUpdate')", $template );

        $this->assertStringNotContainsString( 'return_action', $template,
            'the destination is the controller\'s to know, not a value the browser posts' );
    }

    public function testTheSaveActionIsRegistered(): void {

        $this->assertSame(
            \OWA\Module\MaxmindGeoip\Controller\OptionsGeoipUpdate::class,
            \OWA\Core\Lib::resolveNamespacedClass( 'owa_optionsGeoipUpdateController' ),
            'an unregistered save action 404s the moment someone presses the button' );
    }

    /**
     * An unmapped status code renders as an empty message, which looks like
     * nothing happened.
     */
    public function testTheSuccessMessageExists(): void {

        $base = new class extends \OWA\Core\Base {};

        $this->assertNotEmpty( $base->getMsg( 2500 ) );
    }

    private function mayWrite( $class, $module ) {

        $m = new ReflectionMethod( $class, 'mayWriteModule' );
        $m->setAccessible( true );

        return $m->invoke( ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor(), $module );
    }

    /**
     * The module a setting is saved under comes from the FIELD NAME --
     * config[module.setting] -- so without a declaration it is the browser that
     * decides. A page that knows which module it edits says so, and a field
     * naming anything else is refused rather than written.
     */
    public function testTheGeoipSaveWritesOnlyItsOwnModule(): void {

        $class = \OWA\Module\MaxmindGeoip\Controller\OptionsGeoipUpdate::class;

        $this->assertTrue( $this->mayWrite( $class, 'maxmind_geoip' ) );

        foreach ( [ 'base', 'domstream', '', 'BASE' ] as $other ) {

            $this->assertFalse( $this->mayWrite( $class, $other ),
                sprintf( 'a field naming "%s" was posted to the GeoIP form and must not be saved', $other ) );
        }
    }

    /**
     * The shared controller stays unrestricted, because it cannot know what a
     * third-party settings page intends to write, and silently dropping those
     * writes on upgrade would be worse than the looseness. New pages opt in.
     */
    public function testTheSharedControllerIsUnrestrictedSoExistingFormsKeepWorking(): void {

        $class = \OWA\Module\Base\Controller\OptionsUpdate::class;

        foreach ( [ 'base', 'some_third_party_module' ] as $module ) {

            $this->assertTrue( $this->mayWrite( $class, $module ),
                'restricting the shared controller would break forms that already post to it' );
        }
    }

    /**
     * The narrowing must not be mistaken for the protection: the settings that
     * may never be written from a form are still refused whichever page asks.
     */
    public function testRestrictedSettingsAreStillRefusedForTheModuleThatOwnsThem(): void {

        $m = new ReflectionMethod( \OWA\Module\Base\Controller\OptionsUpdate::class, 'isSensitiveSettingKey' );
        $m->setAccessible( true );

        $denied = \OWA\Module\Base\Classes\Settings::configFileOnlySettings();

        $module = array_key_first( $denied );
        $key    = array_key_first( $denied[ $module ] );

        $this->assertTrue( $m->invoke( null, $module, $key ),
            sprintf( '%s.%s must stay unwritable from any settings form', $module, $key ) );
    }
}
