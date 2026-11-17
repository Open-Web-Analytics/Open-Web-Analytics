<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Hello module's settings page, the example a module author copies,
 * renders through its own View class with the heading every settings page has:
 * an <h1 class="panel_headline"> holding the title the page was registered
 * under (Core\View sets settings_page_title).
 */
final class HelloSettingsPageTest extends TestCase
{
    private function render(): string
    {
        $controller = new \OWA\Core\Controller( [] );
        $controller->setSubview( 'hello.exampleSettings' );

        $data = (array) $controller->data;
        $view = \OWA\Core\CoreAPI::subViewFactory( 'hello.exampleSettings', $data );

        $this->assertInstanceOf( \OWA\Module\Hello\View\ExampleSettings::class, $view );

        $view->render( $data );

        // As Core\View::setSettingsPageTitle() does from the registration.
        $view->body->set( 'settings_page_title', 'Hello World!' );

        return (string) $view->body->fetch();
    }

    public function testTheHeadingIsThePagesH1(): void
    {
        $html = $this->render();

        $this->assertStringContainsString( '<h1 class="panel_headline">Hello World!</h1>', $html );
        $this->assertStringNotContainsString( '<h2', $html );
    }

    public function testTheTextIsAParagraph(): void
    {
        $this->assertMatchesRegularExpression(
            '#<p>\s*Hello world\. This is how you create a settings page\.\s*</p>#', $this->render() );
    }
}
