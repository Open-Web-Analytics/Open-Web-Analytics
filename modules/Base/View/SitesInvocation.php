<?php
namespace OWA\Module\Base\View;

/**
 * Sites Invocation Instructions
 */


class SitesInvocation extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        $site = $this->get('site');

        if ($site->get('name')) {
            $name = sprintf("%s (%s)", $site->get('domain'), $site->get('name'));
        } else {
            $name = $site->get('domain');
        }


        //page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'Tracking Tag' ));
        $this->body->set('site', $site);
        $this->body->set('name', $name);
        $this->body->set('options', []);
        // load body template
        $this->body->set_template('sites_invocation.php');

        $this->body->set('site_id', $this->get('site_id'));

        // A refused save, drawn again: what was posted and each problem by field (SettingsForm::redrawRefused()).
        $this->body->set( 'posted', $this->get( 'posted' ) );

        $this->body->set('tracking_code', \OWA\Core\CoreAPI::getJsTrackerTag( $this->get('site_id') ) );
        $this->body->set( 'bundle_tag', \OWA\Core\CoreAPI::getJsTrackerBundleTag( $this->get( 'site_id' ) ) );

        // False when the Profile has received nothing; the template says so.
        $this->body->set( 'last_event', $this->get( 'last_event' ) ?: 0 );

        // The Profile's tracking bundle: where it is served, whether
        // the file is current, and the last read-back of its cache header.
        $bundle = '\OWA\Module\Base\Classes\TrackerBundle';
        $this->body->set( 'bundle_url', $bundle::url( $this->get( 'site_id' ) ) );
        $this->body->set( 'bundle_status', $bundle::status( $this->get( 'site_id' ) ) );
        $this->body->set( 'bundle_cache', (array) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cache_headers' ) );
        // What the saved settings have the tracker record, one pill each.
        $this->body->set( 'tracked_events', $bundle::trackedEvents( $this->get( 'site_id' ) ) );
    }
}
