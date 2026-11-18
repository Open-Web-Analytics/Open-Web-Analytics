<?php
namespace OWA\Module\Base\Controller;

/**
 * Controller
 */

class ProcessRequest extends \OWA\Module\Base\Controller\ProcessEvent {

    public function action() {

        // Control logic
    }

    public function post() {

        \OWA\Core\CoreAPI::debug('Logging '.$this->event->getEventType().' to event queue...');

        return $this->addToEventQueue();
    }
}
