<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A controller names the template a view renders (Controller::setView(),
 * setSubview()), and a module needs no View class for it. A View class the
 * module ships still resolves, and still has the last word.
 */
final class DeclaredTemplateTest extends TestCase
{
    /** A controller's data, as doAction() returns it, for $declare run on a bare controller. */
    private function declared( callable $declare, array $values = [] ): array
    {
        $controller = new \OWA\Core\Controller( [] );
        $declare( $controller );

        foreach ( $values as $name => $value ) {
            $controller->set( $name, $value );
        }

        return (array) $controller->data;
    }

    public function testAPageIsTheDeclaredTemplateInTheDeclaredWrapperUnderTheDeclaredTitle(): void
    {
        $data = $this->declared( static fn ( $c ) => $c->setView( 'base.noViewClassForThis', [
            'template' => 'json.php',
            'wrapper'  => 'wrapper_public.php',
            'title'    => 'A page',
            'vars'     => [ 'json' ],
        ] ), [ 'json' => '{"set":"by the controller"}' ] );

        $html = (string) \OWA\Core\CoreAPI::displayView( $data );

        $this->assertStringContainsString( '{"set":"by the controller"}', $html );
        $this->assertStringContainsString( '<title>A page - Open Web Analytics</title>', $html );
        $this->assertStringContainsString( 'owa_publicPage', $html, 'the declared wrapper' );
    }

    public function testAVarIsTheControllersValueFalseWhenItSetNoneOrTheValueDeclared(): void
    {
        $view = \OWA\Core\CoreAPI::viewFactory( 'base.noViewClassForThis', $data = $this->declared(
            static fn ( $c ) => $c->setView( 'base.noViewClassForThis', [
                'template' => 'json.php',
                'vars'     => [ 'json', 'neverSet', 'literal' => [ 'a' ] ],
            ] ), [ 'json' => 'x' ] ) );

        $view->setData( $data );
        $view->assembleView( $data );

        $this->assertSame( 'x', $view->body->vars['json'] );
        $this->assertFalse( $view->body->vars['neverSet'], 'as View::get() answers for a key nobody set' );
        $this->assertSame( [ 'a' ], $view->body->vars['literal'] );
    }

    /** The module template trap: the template is the view's module's, not Base's. */
    public function testASubviewIsTheTemplateOfItsOwnModule(): void
    {
        $data = $this->declared( static function ( $c ) {

            $c->setView( 'base.noViewClassForThis', [ 'template' => 'json.php', 'vars' => [ 'json' => '' ] ] );
            $c->setSubview( 'hello.noViewClassForThis', [ 'template' => 'example_settings.php', 'title' => 'ignored' ] );
        } );

        $view = \OWA\Core\CoreAPI::viewFactory( $data['view'], $data );
        $view->setData( $data );
        $view->assembleView( $data );

        $this->assertStringEndsWith( 'modules/Hello/templates/example_settings.php', (string) $view->subview->body->file );
        $this->assertStringContainsString( 'This is how you create a settings page', (string) $view->subview_rendered );
        $this->assertArrayNotHasKey( 'page_title', $view->t->vars, 'a subview names no page title' );
        $this->assertSame( [], $view->subview->body->vars['validation_errors'], 'none, as on a page' );
    }

    public function testAModulesOwnViewClassStillResolves(): void
    {
        $data = $this->declared( static fn ( $c ) => $c->setSubview( 'hello.exampleSettings', [ 'template' => 'json.php' ] ) );

        $this->assertInstanceOf( \OWA\Module\Hello\View\ExampleSettings::class,
            \OWA\Core\CoreAPI::subViewFactory( 'hello.exampleSettings', $data ) );
        $this->assertInstanceOf( \OWA\Module\Base\View\OptionsHierarchy::class,
            \OWA\Core\CoreAPI::viewFactory( 'base.optionsHierarchy' ) );
        $this->assertInstanceOf( \OWA\Core\View\RestApi::class,
            \OWA\Core\CoreAPI::viewFactory( 'base.restApi' ), "Base's views in Core resolve too" );
    }

    public function testAViewClassRendersAfterTheDeclarationAndWins(): void
    {
        $data = $this->declared( static function ( $c ) {

            $c->setView( 'base.noViewClassForThis', [ 'template' => 'json.php', 'vars' => [ 'json' => '' ] ] );
            $c->setSubview( 'hello.exampleSettings', [ 'template' => 'json.php', 'vars' => [ 'json' => 'declared' ] ] );
        } );

        $view = \OWA\Core\CoreAPI::viewFactory( $data['view'], $data );
        $view->setData( $data );
        $view->assembleView( $data );

        $this->assertStringEndsWith( 'modules/Hello/templates/example_settings.php', (string) $view->subview->body->file,
            "the class's render() set its own template" );
    }

    public function testANameWithNeitherAClassNorADeclarationIsRefused(): void
    {
        $this->expectExceptionMessage( 'No View class for base.noViewClassForThis' );

        \OWA\Core\CoreAPI::viewFactory( 'base.noViewClassForThis', [] );
    }

    public function testANameThatIsNotABareIdentifierIsRefusedFirst(): void
    {
        $this->expectException( \OWA\Core\Exception\InvalidAction::class );

        \OWA\Core\CoreAPI::viewFactory( 'base.../x', [ 'templates' => [ 'base.../x' => [ 'template' => 'json.php' ] ] ] );
    }
}
