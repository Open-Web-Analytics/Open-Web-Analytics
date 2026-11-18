<?php
namespace OWA\Core\View;

class AdminPage extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        // Set Page title
        $this->t->set('page_title', $this->get('title'));

        // Set Page headline
        $this->body->set('title', $this->get('title'));
        $this->body->set('titleSuffix', $this->get('titleSuffix'));
        $this->body->set_template('genericAdminPage.php');
        
        $this->setJs('owa.reporting', 'base/dist/owa.reporting-combined-min.js');
        $this->setCss("base/css/owa.reporting-css-combined.css");
    }
}
