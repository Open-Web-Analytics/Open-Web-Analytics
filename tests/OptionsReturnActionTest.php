<?php

use PHPUnit\Framework\TestCase;

/**
 * Saving settings returns to the page that was being edited.
 *
 * The save controller is shared by every settings form and redirected to
 * base.optionsGeneral unconditionally. Right for the page that form came from,
 * wrong for every other one: saving the GeoIP settings landed the administrator
 * on the general settings page, with "Options Saved." attached to a page they
 * had not been editing. It reads as though the save went somewhere unexpected.
 *
 * The submitting page names itself in a hidden field. A page that does not --
 * every form that existed before this -- keeps the old destination.
 *
 * The value is validated because it becomes the next action dispatched. Only a
 * registered action is accepted, so a form value cannot bounce an administrator
 * into an unrelated part of OWA, and a page that is later renamed falls back
 * rather than 404ing after a save that actually succeeded.
 */
final class OptionsReturnActionTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function resolve( $requested ) {

        $controller = ( new ReflectionClass( \OWA\Module\Base\Controller\OptionsUpdate::class ) )
            ->newInstanceWithoutConstructor();

        // The controller reads it as a request parameter.
        $params = new ReflectionProperty( \OWA\Core\Controller::class, 'params' );
        $params->setAccessible( true );
        $params->setValue( $controller, $requested === null ? [] : [ 'return_action' => $requested ] );

        $method = new ReflectionMethod( \OWA\Module\Base\Controller\OptionsUpdate::class, 'returnAction' );
        $method->setAccessible( true );

        return $method->invoke( $controller );
    }

    public function testAFormThatNamesItselfIsReturnedTo(): void {

        $this->assertSame( 'maxmind_geoip.optionsGeoip', $this->resolve( 'maxmind_geoip.optionsGeoip' ),
            'the administrator must land back on the page they were editing' );
    }

    /**
     * Every settings form that existed before this change sends no such field,
     * and must keep working exactly as it did.
     */
    public function testAFormThatNamesNothingKeepsTheOldDestination(): void {

        $this->assertSame( 'base.optionsGeneral', $this->resolve( null ) );
        $this->assertSame( 'base.optionsGeneral', $this->resolve( '' ) );
    }

    /**
     * The field is form input and becomes the next dispatched action, so an
     * unregistered value is refused rather than followed.
     */
    public function testAnUnregisteredActionIsRefused(): void {

        foreach ( [ 'base.somethingThatDoesNotExist', 'base.deleteEverything', '../../etc/passwd' ] as $value ) {

            $this->assertSame( 'base.optionsGeneral', $this->resolve( $value ),
                sprintf( '"%s" is not a registered action and must not be redirected to', $value ) );
        }
    }

    /**
     * The GeoIP form actually carries the field -- the controller change is
     * useless if the template does not name the page.
     */
    public function testTheGeoipFormNamesItself(): void {

        $template = (string) file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/templates/options_geoip.php' );

        $this->assertStringContainsString( 'return_action', $template );
        $this->assertStringContainsString( 'maxmind_geoip.optionsGeoip', $template );
    }

    /**
     * And the message it arrives with is a real one rather than an empty
     * string, which is what an unmapped status code renders as.
     */
    public function testTheSuccessMessageExists(): void {

        $base = new class extends \OWA\Core\Base {};

        $message = $base->getMsg( 2500 );

        $this->assertNotEmpty( $message,
            'status 2500 must map to text, or the redirect arrives with a blank message' );
    }

    /**
     * That action() actually uses returnAction(), which is a separate claim
     * from returnAction() being correct.
     *
     * Without this, reverting the redirect to the hard-coded page passes every
     * other test in this file -- they all call the helper directly and never
     * go through the method that does the redirecting. That mutant survived
     * until this test existed.
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

        // No 'config' in the params, so nothing is persisted and this exercises
        // only the redirect.
        $controller->action();

        $this->assertSame( 'sentinel.returnActionWasConsulted', $data->getValue( $controller )['do'] ?? null,
            'action() must redirect to what returnAction() decided, not to a fixed page' );
    }
}
