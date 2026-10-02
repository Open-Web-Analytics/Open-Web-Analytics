<?php

namespace OWA\Module\Sqs\Controller;

/** Saves the SQS screen; saving the region provisions the queues (Module::onSettingsSaved()). */
class OptionsSqsUpdate extends \OWA\Module\Base\Controller\OptionsUpdate {

    protected function returnAction() {

        return 'sqs.optionsSqs';
    }

    protected function allowedModule() {

        return 'sqs';
    }
}
