<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The two Data Retention pages: the install's and each Property's.
 *
 * Both are install-administrator screens (edit_settings), both save behind a
 * nonce, and both forms carry what owa.retention.js needs to ask before a
 * save: which form it is, and where the preview route is.
 */
final class RetentionPagesTest extends TestCase
{
    const ACTIONS = array(
        'base.optionsRetention'        => \OWA\Module\Base\Controller\OptionsRetention::class,
        'base.optionsRetentionUpdate'  => \OWA\Module\Base\Controller\OptionsRetentionUpdate::class,
        'base.propertyRetention'       => \OWA\Module\Base\Controller\PropertyRetention::class,
        'base.propertyRetentionUpdate' => \OWA\Module\Base\Controller\PropertyRetentionUpdate::class,
        'base.retentionPreviewRest'    => \OWA\Module\Base\Controller\RetentionPreviewRest::class,
    );

    public function testEveryActionAndTheRouteAreRegistered(): void
    {
        $actions = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'actions' );

        foreach ( array_keys( self::ACTIONS ) as $action ) {
            $this->assertArrayHasKey( $action, $actions, "$action is linked to but not registered" );
        }

        $this->assertNotEmpty( \OWA\Core\CoreAPI::serviceSingleton()->getRestApiRoute( 'base', 'v1', 'retentionPreview', 'GET' ),
            'GET v1/retentionPreview is what the confirmation asks' );
    }

    /** Retention is an install administrator's, even where it is set per Property. */
    public function testEveryScreenNeedsEditSettings(): void
    {
        foreach ( self::ACTIONS as $action => $class ) {
            $this->assertSame( 'edit_settings', ( new $class( array() ) )->getRequiredCapability(), $action );
        }

        $nonce = new \ReflectionProperty( \OWA\Core\Controller::class, 'is_nonce_required' );
        $nonce->setAccessible( true );

        foreach ( array( 'base.optionsRetentionUpdate', 'base.propertyRetentionUpdate' ) as $action ) {
            $class = self::ACTIONS[ $action ];
            $this->assertTrue( $nonce->getValue( new $class( array() ) ), "$action saves, so it needs a nonce" );
        }
    }

    public function testTheInstallSaveReturnsToItsOwnPage(): void
    {
        $m = new \ReflectionMethod( \OWA\Module\Base\Controller\OptionsRetentionUpdate::class, 'returnAction' );
        $m->setAccessible( true );

        $this->assertSame( 'base.optionsRetention', $m->invoke( new \OWA\Module\Base\Controller\OptionsRetentionUpdate( array() ) ) );
    }

    /** Both windows on the install page, in a form the confirmation binds to. */
    public function testTheInstallPageRendersBothWindows(): void
    {
        $template = new \OWA\Core\Template( 'base' );
        $template->set_template( 'options_retention.php' );
        $template->set( 'settings_fieldsets', owa_test_page_fieldsets( 'base.optionsRetention', \OWA\Module\Base\Module::class ) );
        $template->set( 'settings_page_title', 'Data Retention' );

        $html = (string) $template->fetch();

        $this->assertNotSame( '', trim( $html ), 'an empty render is a template error' );
        $this->assertStringContainsString( 'config[base.raw_retention_months]', $html );
        $this->assertStringContainsString( 'config[base.cube_retention_months]', $html );
        $this->assertStringContainsString( 'data-owa-retention-form="install"', $html );
        $this->assertStringContainsString( 'data-owa-retention-preview=', $html );
        $this->assertStringContainsString( 'retentionPreview', $html );
        $this->assertStringContainsString( 'value="base.optionsRetentionUpdate"', $html );
    }

    private function renderPropertyPage(): string
    {
        $template = new \OWA\Core\Template( 'base' );
        $template->set_template( 'property_retention.php' );
        $template->set( 'headline', 'Data Retention' );
        $template->set( 'property', array( 'id' => '7781000000000009', 'name' => 'Alice\'s shop' ) );

        return (string) $template->fetch();
    }

    /**
     * The Property's window in a form that names the Property. With nothing set
     * for the install it is a plain field whose blank says "Same as event
     * data"; with an install window, it inherits that until overridden.
     */
    public function testThePropertyPageRendersItsWindow(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting( 'base', 'cube_retention_months' );

        try {
            \OWA\Core\CoreAPI::setSetting( 'base', 'cube_retention_months', null );
            $html = $this->renderPropertyPage();

            $this->assertStringContainsString( 'config[base.cube_retention_months]', $html );
            $this->assertStringContainsString( 'placeholder="Same as event data"', $html );
            $this->assertStringNotContainsString( 'data-owa-override=', $html, 'nothing set above, nothing to override' );
            $this->assertStringContainsString( 'data-owa-retention-form="property"', $html );
            $this->assertStringContainsString( 'data-owa-retention-property="7781000000000009"', $html );
            $this->assertStringContainsString( 'Alice&#039;s shop', $html, 'named, and escaped' );

            \OWA\Core\CoreAPI::setSetting( 'base', 'cube_retention_months', 12 );
            $this->assertStringContainsString( 'data-owa-override=', $this->renderPropertyPage(),
                'it inherits the install\'s window until overridden' );
        } finally {
            \OWA\Core\CoreAPI::setSetting( 'base', 'cube_retention_months', $was );
        }
    }

    /** The Property tier of the settings nav links its Data Retention page, for administrators only. */
    public function testThePropertyNavLinksThePage(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyRetention( array() );
        $nav = new \ReflectionMethod( \OWA\Core\Controller::class, 'getHierarchyNav' );
        $nav->setAccessible( true );

        $entries = array_filter( (array) ( $nav->invoke( $controller, '', '7781000000000009' )['Property'] ?? array() ),
            fn ( $e ) => $e['do'] === 'base.propertyRetention' );

        $this->assertCount( 1, $entries );
        $entry = reset( $entries );
        $this->assertSame( 'Data Retention', $entry['label'] );
        $this->assertSame( 'edit_settings', $entry['capability'] );
        $this->assertSame( array( 'propertyId' => '7781000000000009' ), $entry['params'] );
    }

    /** A save names a Property that exists, and a window the declaration accepts. */
    public function testThePropertySaveIsValidated(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'loads a Property' );
        }

        foreach ( array(
            array( 'propertyId' => '999999999999' ),
            array(),
        ) as $params ) {
            $this->assertContains( 'propertyId', $this->validatedNames( $params ), json_encode( $params ) );
        }
    }

    /** @return string[] the names the controller's validator was given */
    private function validatedNames( array $params ): array
    {
        $controller = new \OWA\Module\Base\Controller\PropertyRetentionUpdate( $params );
        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        if ( ! $validator ) {
            return array();
        }

        $list = new \ReflectionProperty( $validator, 'validations' );
        $list->setAccessible( true );

        return array_column( (array) $list->getValue( $validator ), 'name' );
    }
}
