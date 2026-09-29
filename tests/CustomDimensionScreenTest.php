<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Dimensions;

/**
 * The custom dimensions screen.
 *
 * The screen exists because the CLI is not where a site author lives, and the
 * mechanism behind it was built to be callable from one: registering writes a
 * row and returns, because the ALTER it implies is a full table rebuild and is
 * past every request timeout there is.
 *
 * So most of what is worth testing here is the SEAM -- that the screen asks the
 * registrar rather than restating its rules, that a refusal arrives with the
 * registrar's own words rather than as "could not save", and that what it shows
 * as room is the measured budget and not the constant.
 */
final class CustomDimensionScreenTest extends TestCase
{
    const PROPERTY = 7772000000000001;

    private function validatedNames( $controller ): array
    {
        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        if ( ! $validator ) {
            return [];
        }

        $property = new \ReflectionProperty( $validator, 'validations' );
        $property->setAccessible( true );

        $names = [];

        foreach ( (array) $property->getValue( $validator ) as $validation ) {
            $names[] = $validation['name'];
        }

        return $names;
    }

    /** Every error message a validator was given. */
    private function validationMessages( $controller ): string
    {
        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        if ( ! $validator ) {
            return '';
        }

        $property = new \ReflectionProperty( $validator, 'validations' );
        $property->setAccessible( true );

        $messages = [];

        foreach ( (array) $property->getValue( $validator ) as $validation ) {
            $messages[] = $validation['conf']['errorMsg'] ?? '';
        }

        return implode( ' | ', $messages );
    }

    public function testTheScreensAreRegisteredWhereTheNavPointsThem(): void
    {
        $actions = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'actions' );

        foreach ( ['base.customDimensions', 'base.customDimensionEdit',
                   'base.customDimensionSave', 'base.customDimensionDelete'] as $action ) {

            $this->assertArrayHasKey( $action, $actions,
                "$action is linked to but not registered, so the screen 404s." );
        }
    }

    /**
     * The nav points at it, under Property.
     *
     * Custom dimensions belong to the Property because the cube does. Filed
     * anywhere else, two Properties would appear to share a namespace they do
     * not share.
     */
    public function testTheNavOffersItUnderProperty(): void
    {
        $source = file_get_contents( __DIR__ . '/../Core/Controller.php' );

        $property = substr( $source, strpos( $source, "\$nav['Property']" ) );
        $property = substr( $property, 0, strpos( $property, "\$nav['Observation Profile']" ) );

        $this->assertStringContainsString( 'base.customDimensions', $property,
            'the entry belongs in the Property group, beside goal events' );
    }

    /**
     * BOTH MUTATIONS ARE GATED AND NONCED.
     *
     * Registering issues DDL against the table every report reads, and removing
     * one drops a column. Neither should be reachable by a link somebody was
     * induced to follow.
     */
    public function testBothMutationsAreGuarded(): void
    {
        foreach ( [
            new \OWA\Module\Base\Controller\CustomDimensionSave( [] ),
            new \OWA\Module\Base\Controller\CustomDimensionDelete( [] ),
        ] as $controller ) {

            $this->assertSame( 'edit_settings', $controller->getRequiredCapability(),
                get_class( $controller ) . ' must be capability-gated' );

            $nonce = new \ReflectionProperty( \OWA\Core\Controller::class, 'is_nonce_required' );
            $nonce->setAccessible( true );

            $this->assertTrue( $nonce->getValue( $controller ),
                get_class( $controller ) . ' must require a nonce' );
        }
    }

    public function testANamelessRegistrationIsRefused(): void
    {
        $controller = new \OWA\Module\Base\Controller\CustomDimensionSave(
            ['propertyId' => (string) self::PROPERTY, 'dimensionKey' => '   '] );

        $controller->validate();

        $this->assertContains( 'dimensionKey', $this->validatedNames( $controller ) );
    }

    public function testARegistrationWithNoPropertyIsRefused(): void
    {
        $controller = new \OWA\Module\Base\Controller\CustomDimensionSave(
            ['dimensionKey' => 'plan'] );

        $controller->validate();

        $this->assertContains( 'propertyId', $this->validatedNames( $controller ) );
    }

    /**
     * A REFUSAL ARRIVES IN THE REGISTRAR'S OWN WORDS.
     *
     * Every refusal it produces explains itself -- the name pattern, the
     * duplicate, the budget, the Property with no cube. A screen that replaced
     * them with "could not save" would throw all of that away at the one moment
     * somebody needs it, and would leave the CLI and the screen telling
     * different stories about the same rule.
     */
    public function testABadNameComesBackWithTheReasonRatherThanAGenericFailure(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the refusal is asked of the registrar, which reads tables' );
        }

        $controller = new \OWA\Module\Base\Controller\CustomDimensionSave(
            ['propertyId' => (string) self::PROPERTY, 'dimensionKey' => 'my.key',
             'scope' => 'event'] );

        $controller->validate();

        $messages = $this->validationMessages( $controller );

        $this->assertNotSame( '', $messages );
        $this->assertStringNotContainsStringIgnoringCase( 'could not save', $messages );
    }

    /**
     * The screen asks the registrar rather than restating its rules.
     *
     * Two copies of "what is a usable dimension" would drift, and the CLI and
     * the screen would refuse different things.
     */
    public function testTheScreenDelegatesTheRulesRatherThanRepeatingThem(): void
    {
        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Controller/CustomDimensionSave.php' );

        $this->assertStringContainsString( 'Dimensions::refusalFor', $body );

        // The pattern itself must live in one place only.
        $this->assertStringNotContainsString( 'A-Za-z0-9_', $body,
            'the name pattern belongs to Dimensions, not to the screen' );
    }

    /**
     * refusalFor() and register() agree, because they are the same rules.
     *
     * The pre-check is advisory -- the answer can change between it and the
     * write -- but the two must not disagree about a request that has not
     * changed, or the form would accept what the registrar then refuses.
     */
    public function testThePreCheckAgreesWithTheRegistrar(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'both read tables' );
        }

        foreach ( ['my.key', '1plan', '', str_repeat( 'a', 41 )] as $bad ) {

            $request = ['key' => $bad, 'scope' => 'event'];

            $this->assertNotSame( '',
                Dimensions::refusalFor( self::PROPERTY, $request ),
                var_export( $bad, true ) . ' should be refused by the pre-check' );

            $result = Dimensions::register( self::PROPERTY, [$request] );

            $this->assertFalse( $result['ok'],
                var_export( $bad, true ) . ' should be refused by the registrar too' );
        }
    }

    /**
     * A PROPERTY WITH NO CUBE IS AN ORDINARY STATE, not an error.
     *
     * A cube is created by a build on the Property's first data, so every
     * Property that has not collected anything is in this state. The screen
     * says so rather than offering a form that cannot work.
     */
    public function testAPropertyWithNoCubeIsToldSoRatherThanOfferedAForm(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'this asks whether a table exists' );
        }

        $state = \OWA\Module\Base\Controller\CustomDimensions::cubeState( self::PROPERTY );

        $this->assertFalse( $state['exists'] );
        $this->assertSame( Cubes::tableFor( self::PROPERTY ), $state['table'] );

        $refusal = Dimensions::refusalFor( self::PROPERTY, ['key' => 'plan', 'scope' => 'event'] );

        $this->assertStringContainsString( 'no reporting cube yet', $refusal );
    }

    /**
     * WHAT THE SCREEN SHOWS AS ROOM IS MEASURED, NOT THE CONSTANT.
     *
     * Twenty is an outer cap; how many a server actually takes depends on which
     * row limit binds there, and that differs by MySQL version. Showing the cap
     * where the server allows fewer would promise room the next registration
     * refuses.
     */
    public function testTheRoomShownIsTheMeasuredBudget(): void
    {
        $body = file_get_contents(
            __DIR__ . '/../modules/Base/Controller/CustomDimensions.php' );

        $this->assertStringContainsString( 'capacityFor', $body,
            'the screen must ask what fits rather than print the cap' );

        $template = file_get_contents(
            __DIR__ . '/../modules/Base/templates/custom_dimensions.php' );

        $this->assertStringContainsString( "cube['capacity']", $template );
        $this->assertStringContainsString( "cube['cap']", $template,
            'and say so when the two differ, rather than leaving a smaller number unexplained' );
    }

    /**
     * The screens say the column is not there yet.
     *
     * The gap between registering and reporting is the one thing about this
     * design a person would otherwise read as a fault: the list says a
     * registration is being added, and the register screen says past events
     * are not filled in.
     */
    public function testTheScreensExplainThatTheColumnArrivesLater(): void
    {
        $list = file_get_contents(
            __DIR__ . '/../modules/Base/templates/custom_dimensions.php' );

        $this->assertStringContainsString( 'Being added', $list );

        $edit = file_get_contents(
            __DIR__ . '/../modules/Base/templates/custom_dimension_edit.php' );

        $this->assertStringContainsString( 'rebuild the cube', $edit,
            'and that nothing already collected is filled in automatically' );
    }

    /** Each template beside the View that renders it. */
    public static function screens(): array
    {
        return [
            'list'     => ['custom_dimensions.php', 'CustomDimensions.php'],
            'register' => ['custom_dimension_edit.php', 'CustomDimensionEdit.php'],
        ];
    }

    /**
     * EVERY VARIABLE THE TEMPLATE READS IS ONE THE VIEW SETS.
     *
     * ViewScope throws on an unset variable rather than rendering an empty
     * string, so a template reading something the view forgot is a blank
     * screen, not a blank field -- and the two halves are in different files
     * with nothing but habit holding them together.
     *
     * @dataProvider screens
     */
    public function testTheTemplateReadsNothingTheViewDoesNotSet( string $template, string $viewFile ): void
    {
        $markup = file_get_contents( __DIR__ . '/../modules/Base/templates/' . $template );

        $view = file_get_contents( __DIR__ . '/../modules/Base/View/' . $viewFile );

        // $view->name, but not $view->method(...)
        preg_match_all( '/\$view->([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $markup, $matches );

        $this->assertNotEmpty( $matches[1], 'the scan found nothing, so it proves nothing' );

        foreach ( array_unique( $matches[1] ) as $name ) {

            $this->assertMatchesRegularExpression(
                "/'" . preg_quote( $name, '/' ) . "'/",
                $view,
                "$template reads \$view->$name and $viewFile never sets it, so the "
              . 'screen renders as nothing' );
        }
    }

    /**
     * Every form on either screen carries a nonce field.
     *
     * @dataProvider screens
     */
    public function testEveryFormIsNonced( string $template ): void
    {
        $markup = file_get_contents( __DIR__ . '/../modules/Base/templates/' . $template );

        $this->assertSame(
            substr_count( $markup, '<form method="post"' ),
            substr_count( $markup, 'createNonceFormField' ),
            'a form without a nonce is a mutation any page could trigger' );
    }

    /** A template as it renders, given only what its View sets. */
    private function render( string $template, array $vars ): string
    {
        $t = new \OWA\Core\Template( 'base' );

        foreach ( $vars as $k => $v ) {
            $t->set( $k, $v );
        }

        $this->assertTrue( $t->set_template( $template ) );

        return $t->fetch();
    }

    private function cube( bool $exists = true, int $capacity = 20 ): array
    {
        return ['table' => 'owa_event_x', 'exists' => $exists, 'capacity' => $capacity, 'cap' => 20];
    }

    private function renderList( array $overrides = [] ): string
    {
        return $this->render( 'custom_dimensions.php', array_merge( [
            'siteId'     => 'site-a',
            'propertyId' => (string) self::PROPERTY,
            'dimensions' => [],
            'cube'       => $this->cube(),
        ], $overrides ) );
    }

    private function renderRegister( array $overrides = [] ): string
    {
        return $this->render( 'custom_dimension_edit.php', array_merge( [
            'siteId'            => 'site-a',
            'propertyId'        => (string) self::PROPERTY,
            'cube'              => $this->cube(),
            'used'              => 0,
            'scopes'            => ['event', 'user'],
            'types'             => ['string', 'integer'],
            'submitted'         => ['dimensionKey' => '', 'scope' => '', 'dataType' => '', 'label' => ''],
            'validation_errors' => [],
        ], $overrides ) );
    }

    private function registered( int $count ): array
    {
        $rows = [];

        for ( $i = 1; $i <= $count; $i++ ) {
            $rows[] = ['dimension_key' => "plan$i", 'label' => "Plan $i", 'scope' => 'event',
                       'data_type' => 'string', 'state' => 'applied', 'state_message' => ''];
        }

        return $rows;
    }

    /**
     * REGISTERING IS ITS OWN SCREEN, reached from the list.
     *
     * The list links to base.customDimensionEdit the way Goal Events links to
     * its editor, and carries no registration form of its own.
     */
    public function testTheListLinksToTheRegisterScreenAndHoldsNoForm(): void
    {
        $html = $this->renderList();

        $this->assertStringContainsString( 'base.customDimensionEdit', $html,
            'the list offers no way to register one' );
        $this->assertStringNotContainsString( 'base.customDimensionSave', $html,
            'the registration form belongs on its own screen' );
    }

    /** With no room, or no cube, the list offers nothing to register. */
    public function testTheListOffersNoRegisterLinkWhenNothingCanBeRegistered(): void
    {
        $full = $this->renderList( [
            'dimensions' => $this->registered( 2 ),
            'cube'       => $this->cube( true, 2 ),
        ] );

        $this->assertStringNotContainsString( 'base.customDimensionEdit', $full );
        $this->assertStringContainsString( '2 of 2 used.', $full );

        $noCube = $this->renderList( ['cube' => $this->cube( false )] );

        $this->assertStringNotContainsString( 'base.customDimensionEdit', $noCube );
        $this->assertStringContainsString( 'has not collected anything yet', $noCube );
    }

    /** The register screen carries the whole form, posting to the save action. */
    public function testTheRegisterScreenCarriesTheForm(): void
    {
        $html = $this->renderRegister();

        foreach ( ['dimensionKey', 'label', 'scope', 'dataType', 'base.customDimensionSave',
                   'propertyId', 'siteId'] as $needle ) {

            $this->assertStringContainsString( $needle, $html, "$needle is missing from the form" );
        }
    }

    /**
     * A REFUSED FORM COMES BACK WITH THE REASON BESIDE THE NAME and what was
     * typed still in the fields.
     */
    public function testARefusalShowsTheReasonAndWhatWasTyped(): void
    {
        $html = $this->renderRegister( [
            'validation_errors' => ['dimensionKey' => 'Alice is not a usable name.'],
            'submitted'         => ['dimensionKey' => 'bob.plan', 'scope' => 'user',
                                    'dataType' => 'integer', 'label' => 'Bob plan'],
        ] );

        $this->assertMatchesRegularExpression(
            '/class="validation_error">Alice is not a usable name\.</', $html );
        $this->assertStringContainsString( 'value="bob.plan"', $html );
        $this->assertStringContainsString( 'value="Bob plan"', $html );
        $this->assertMatchesRegularExpression( '/value="user"\s+selected/', $html );
        $this->assertMatchesRegularExpression( '/value="integer"\s+selected/', $html );
    }

    /**
     * Reached by URL with nothing to register against, the screen says why
     * instead of offering a form the registrar would refuse.
     */
    public function testTheRegisterScreenOffersNoFormWhenNothingCanBeRegistered(): void
    {
        $noCube = $this->renderRegister( ['cube' => $this->cube( false )] );

        $this->assertStringContainsString( 'has not collected anything yet', $noCube );
        $this->assertStringNotContainsString( 'base.customDimensionSave', $noCube );

        $full = $this->renderRegister( ['cube' => $this->cube( true, 2 ), 'used' => 2] );

        $this->assertStringContainsString( 'no room for another', $full );
        $this->assertStringNotContainsString( 'base.customDimensionSave', $full );
    }

    /**
     * A refusal renders the REGISTER screen, not the list, carrying what was
     * typed.
     */
    public function testARefusalRendersTheRegisterScreen(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'errorAction() resolves the site and its Property' );
        }

        $controller = new \OWA\Module\Base\Controller\CustomDimensionSave( [
            'propertyId' => (string) self::PROPERTY, 'dimensionKey' => ' bob.plan ',
            'scope' => 'user', 'dataType' => 'integer', 'label' => 'Bob plan',
        ] );

        $controller->errorAction();

        $this->assertSame( 'base.customDimensionEdit', $controller->data['subview'] );
        $this->assertSame( [
            'dimensionKey' => 'bob.plan', 'scope' => 'user',
            'dataType' => 'integer', 'label' => 'Bob plan',
        ], $controller->data['submitted'] );
    }

}
