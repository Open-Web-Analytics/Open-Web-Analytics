<?php
namespace OWA\Module\Base\View;

/**
 * Installation View
 */

class Install extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        //page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'Installation' ));

        // load wrapper template
        $this->t->set_template('wrapper_public.php');
        // load body template
        $this->body->set_template('install.php');

        $this->setCss("base/css/owa.css");
        $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Welcome to the Open Web Analytics Installation Wizard' ));
        $this->body->set('step', $data['subview']);
        $this->setJs("owa", "base/dist/owa.reporting-combined-min.js");
    }
}
