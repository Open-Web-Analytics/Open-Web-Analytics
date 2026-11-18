<?php
namespace OWA\Module\Base\View;

/**
 * Overlay Launcher Controller View
 */

class OverlayLauncher extends \OWA\Core\View {
 
    /**
     * @return void
     */
    public function render() {
        // Assign Data to templates
         $this->t->set_template('overlay_launcher.php');
        $this->t->set('url', $this->get('url'));
    }
}
