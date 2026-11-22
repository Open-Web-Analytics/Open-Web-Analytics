<?php
namespace OWA\Module\Base\Controller;

/**
 * Saves the install's Updates page. The release check reads the settings at
 * its next run, within six hours, or at once with "Check now".
 */
class OptionsUpdatesUpdate extends OptionsUpdate {

    protected function returnAction() {

        return 'base.optionsUpdates';
    }
}
