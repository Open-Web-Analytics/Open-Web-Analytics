<?php

use PHPUnit\Framework\TestCase;

/**
 * The GeoIP settings page renders, and says what an administrator needs.
 *
 * The module had no settings page at all. Its licence key -- without which the
 * database cannot be downloaded -- could only be set by hand-editing
 * owa-config.php, and nothing in the interface said so, or that a key was
 * needed, or that the database goes stale.
 *
 * WHY THE RENDER IS ASSERTED AND NOT JUST THE WIRING
 * A template that fails renders as NOTHING, not as an error. ViewScope raises
 * on a view variable that was never set, that exception unwinds out of
 * include(), and the finally in TemplateEngine::fetch() discards the output
 * buffer. The page comes back empty and the reason is gone. So the only useful
 * assertion is that real markup comes out with the fields in it.
 */
final class MaxmindOptionsPageTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function render( array $overrides = array() ): string {

        $template = new \OWA\Core\Template( 'maxmind_geoip' );

        // setTemplateFile(), not set_template(): Template's constructor only
        // honours the module it is given when caller_params carries one, and
        // Core\View does not pass any -- so set_template() would look in Base's
        // directory and find nothing. A module template must name its module.
        $template->setTemplateFile( 'maxmind_geoip', 'options_geoip.php' );

        $data = array_merge( array(
            'configuration' => array( 'db_license_key' => 'test-key-value' ),
            'editions'      => \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS,
            'edition'       => 'GeoLite2-City',
            'db_file'       => '/tmp/GeoLite2-City.mmdb',
            'db_present'    => false,
            'db_updated'    => 0,
            'has_key'       => true,
        ), $overrides );

        foreach ( $data as $key => $value ) {
            $template->set( $key, $value );
        }

        return (string) $template->fetch();
    }

    public function testThePageRendersRatherThanFailingSilently(): void {

        $html = $this->render();

        $this->assertNotSame( '', $html,
            'an empty render is what a template error looks like -- the buffer is discarded' );
        $this->assertStringContainsString( 'GeoIP Settings', $html );
    }

    /**
     * The two settings the page exists for, named exactly as the generic save
     * controller expects: config[module.setting].
     */
    public function testItPostsBothSettingsUnderTheirRealNames(): void {

        $html = $this->render();

        $this->assertStringContainsString( 'config[maxmind_geoip.db_license_key]', $html,
            'the key field must post under the name persistSetting() will store' );
        $this->assertStringContainsString( 'config[maxmind_geoip.db_edition]', $html );
        $this->assertStringContainsString( 'value="maxmind_geoip"', $html,
            'the form must tell the save controller which module it is saving' );
    }

    public function testEveryReadableEditionIsOfferedAndTheCurrentOneSelected(): void {

        $html = $this->render( array( 'edition' => 'GeoLite2-Country' ) );

        foreach ( \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS as $edition ) {
            $this->assertStringContainsString( $edition, $html );
        }

        $this->assertMatchesRegularExpression(
            '/GeoLite2-Country"\s*\n?\s*SELECTED/i',
            $html,
            'the edition in use must come back selected, or saving would silently change it'
        );
    }

    /**
     * The page's real job. A missing key and a missing database both present as
     * "locations are blank", and neither was visible anywhere before this.
     */
    public function testItSaysWhenGeolocationCannotWork(): void {

        $html = $this->render( array( 'has_key' => false, 'db_present' => false ) );

        $this->assertStringContainsString( 'No key is set', $html );
        $this->assertStringContainsString( 'No database is installed', $html );
    }

    public function testItReportsTheDatabaseAgeWhenOneIsInstalled(): void {

        $html = $this->render( array(
            'db_present' => true,
            'db_updated' => mktime( 0, 0, 0, 3, 14, 2026 ),
        ) );

        $this->assertStringContainsString( '14 Mar 2026', $html,
            'a database that is present should say how old it is -- staleness is the failure mode' );
        $this->assertStringNotContainsString( 'No database is installed', $html );
    }

    /**
     * The key is rendered into an HTML attribute, so it has to be escaped. A
     * key is opaque and could contain anything.
     */
    public function testTheLicenceKeyIsEscapedIntoTheField(): void {

        $html = $this->render( array(
            'configuration' => array( 'db_license_key' => 'a"><script>x</script>' ),
        ) );

        $this->assertStringNotContainsString( '<script>x</script>', $html,
            'the stored key must not be able to break out of the value attribute' );
        $this->assertStringContainsString( '&quot;', $html );
    }

    public function testTheCommandIsNamedSoTheresSomethingToDoNext(): void {

        $this->assertStringContainsString( 'cmd=update-geoip-db', $this->render(),
            'a settings page that cannot say how to get the database is only half the answer' );
    }

    /**
     * The View must resolve the module's own template, which is a different
     * assertion from the template rendering.
     *
     * This is the mistake that was actually made. Template's constructor only
     * honours the module it is handed when caller_params carries one, and
     * Core\View constructs it without any -- so set_template() searches Base's
     * directory, finds nothing, and the page renders empty with no error.
     * Every test above would still have passed, because they render the
     * template directly and never go through the View.
     */
    public function testTheViewResolvesTheModulesOwnTemplate(): void {

        $view = new \OWA\Module\MaxmindGeoip\View\OptionsGeoip();

        $view->render( array(
            'configuration' => array(),
            'editions'      => \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS,
            'edition'       => 'GeoLite2-City',
            'db_file'       => '/tmp/GeoLite2-City.mmdb',
            'db_present'    => false,
            'db_updated'    => 0,
            'has_key'       => false,
        ) );

        $file = (string) $view->body->file;

        $this->assertNotSame( '', $file,
            'an unresolved template renders as nothing at all, silently' );

        $this->assertStringContainsString( 'modules/MaxmindGeoip/templates/options_geoip.php', $file,
            'the View must point at this module\'s template, not at Base\'s directory' );
    }
}
