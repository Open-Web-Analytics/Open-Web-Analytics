<?php
namespace OWA\Module\Base\Controller;

/**
 * Saves the install's Data Retention page.
 *
 * The windows themselves are applied by the next rotate-partitions run. What
 * happens here is the one thing that should not wait for it: a reporting
 * window made longer queues the rebuild of the months it now covers.
 */
class OptionsRetentionUpdate extends OptionsUpdate {

    public function action() {

        parent::action();

        \OWA\Module\Base\Classes\Retention::enqueueBackfills( \OWA\Module\Base\Classes\Retention::backfills() );
    }

    protected function returnAction() {

        return 'base.optionsRetention';
    }
}
