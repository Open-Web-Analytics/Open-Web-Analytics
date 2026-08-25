<?php

namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\NotificationManager;

/**
 * Fetch release announcements and store them as notifications.
 *
 * Runs on the scheduler. What it replaces was an outbound request to
 * api.github.com in the critical path of every dashboard render -- uncached,
 * never stored, and a slow network made the dashboard slow.
 *
 * Idempotent, and it has to be: it sees the same releases on every run and
 * stores only the ones it does not already have.
 */
class NotificationsFetchCli extends \OWA\Core\Controller\Cli {

    function action() {

        $url = \OWA\Core\CoreAPI::getSetting( 'base', 'owa_news_url' );

        if ( ! $url ) {

            $this->e->notice( 'No owa_news_url configured; nothing to fetch.' );

            return;
        }

        $http     = new \OWA\Core\Http();
        $response = $http->getRequest( $url );

        if ( ! $response ) {

            // A failed fetch is not a failed job: the next run will try again
            // and nothing downstream depends on this having succeeded now.
            $this->e->notice( sprintf( 'Could not reach %s; leaving notifications unchanged.', $url ) );

            return;
        }

        $decoded = json_decode( $response );

        if ( ! is_array( $decoded ) ) {

            /*
             * The releases endpoint answers with an OBJECT carrying a
             * `message` when it is unhappy -- rate limited, or the repository
             * moved. Storing that as a notification would put "API rate limit
             * exceeded" in front of every operator, so a non-list is a
             * non-event.
             */
            $this->e->notice( sprintf( 'Unexpected response from %s; nothing stored.', $url ) );

            return;
        }

        $items   = NotificationManager::fromGithubReleases( $decoded );
        $created = NotificationManager::record(
            $items, NotificationManager::SOURCE_GITHUB, '', NotificationManager::TYPE_RELEASE );

        $this->e->notice( sprintf(
            '%d release(s) seen, %d new notification(s) stored.', count( $items ), $created ) );
    }
}
