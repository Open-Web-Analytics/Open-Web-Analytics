<?php
namespace OWA\Module\Domstream;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Domstream: recordings of what a visitor's pointer, scroll, clicks and key
 * presses did on a page, and their playback.
 *
 * Everything the server knows about recordings is here, and only while the
 * module is active: the `domstream` tracking event (routed to
 * domstream.processEvent by name), its two tables and their updates, the
 * report, the playback endpoint and the nav entries. The recorder and the
 * player are this module's source, compiled into the tracker bundle by its
 * build manifest.
 *
 * Key presses are recorded as THAT a key was pressed in a field -- never
 * which key.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */
class Module extends \OWA\Core\Module {

    /** The event name the recorder sends: dispatched as tracking.domstream. */
    const EVENT_NAME = 'domstream';

    function __construct() {

        $this->name = 'domstream';
        $this->display_name = 'Domstream';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '2.0';
        $this->description = 'Records the pointer, scroll, clicks and key presses on a page, for playback.';
        $this->config_required = false;
        $this->required_schema_version = 2;

        parent::__construct();

        $this->registerTrackingProperties( 'regular', self::trackingProperties() );
    }

    /**
     * The fields a chunk carries beyond what every event does.
     *
     * Registered so log.php's allowlist admits them: it passes only registered
     * client properties, and the tracker's common ones (site, visitor, session,
     * page) are Base's. Scoped to this event; none is a column of the event
     * table, since a chunk is never stored as an event.
     *
     * @return array property name => definition
     */
    public static function trackingProperties() {

        $properties = array();

        foreach ( array(
            'recording_id'  => 'string',
            'seq'           => 'integer',
            'page_view_seq' => 'integer',
            'offset_ms'     => 'integer',
            'duration_ms'   => 'integer',
            'viewport_w'    => 'integer',
            'viewport_h'    => 'integer',
            'samples'       => 'json',
        ) as $name => $type ) {

            $properties[ $name ] = array(
                'set_by'    => 'client',
                'from'      => array( $name ),
                'events'    => array( self::EVENT_NAME ),
                'data_type' => $type,
                'required'  => false,
                'callbacks' => array(),
            );
        }

        return $properties;
    }

    /**
     * Registered by class name with no path: the class is PSR-4, so it is
     * autoloaded and the path registerAction() would prefix is never read.
     */
    function registerActions() {

        // Its tag settings, beside Base's on every Tracking Tag screen (PLAN 2.24.4).
        $this->registerSettingsFieldSet( array(
            'id'       => 'trackingTag',
            'group'    => 'tracking_tag',
            'legend'   => 'Page Interaction Recording',
            'settings' => array( 'record', 'sample_rate' ),
        ) );

        $this->registerAction( 'domstream.processEvent',
            'OWA\\Module\\Domstream\\Controller\\ProcessEvent', '' );
        $this->registerAction( 'domstream.reportDomstreams',
            'OWA\\Module\\Domstream\\Controller\\ReportDomstreams', '' );
        $this->registerAction( 'domstream.domstreamsRest',
            'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController', '' );
    }

    function _registerEntities() {

        $this->registerEntity( array( 'domstream_chunk', 'domstream_payload' ) );
    }

    /** A chunk goes to this module's processor, never to Base's. */
    function _registerEventProcessors() {

        $this->addTrackingEventProcessor( self::EVENT_NAME, 'domstream.processEvent' );
    }

    function registerFilters() {

        $this->registerFilter( 'tracker_tag_cmds', $this, 'addToTracker', 99 );
        $this->registerFilter( 'tracker_bundle_config', $this, 'addToBundle', 99 );
        $this->registerFilter( 'report_links', $this, 'addReportLinks', 10 );
    }

    /**
     * A Profile's tracking bundle records when the Profile says so (PLAN 2.24):
     * the sample rate among its options, trackDomStream among its features, and
     * the recorder's chunk inlined.
     *
     * @param  array  $config  options, features, plugins
     * @param  string $site_id
     * @return array
     */
    function addToBundle( $config, $site_id = '' ) {

        $setting = function ( $key ) use ( $site_id ) {

            return \OWA\Core\CoreAPI::getSetting( 'domstream', $key, 'profile', (string) $site_id );
        };

        if ( ! $setting( 'record' ) ) {

            return $config;
        }

        $config['options'][]  = array( 'setDomstreamSampleRate', (int) $setting( 'sample_rate' ) );
        $config['features'][] = array( 'trackDomStream' );
        $config['plugins'][]  = 'domstream';

        return $config;
    }

    /** The snippet starts the recorder. */
    function addToTracker( $cmds ) {

        $cmds[] = "owa_cmds.push(['trackDomStream']);";

        return $cmds;
    }

    /**
     * Recordings from the reports that lead to them: Page Detail's "more
     * analytics" (this page's recordings) and Content's related reports.
     */
    function addReportLinks( $links, $reportId = '', $widgetId = '' ) {

        if ( $reportId === 'document' && $widgetId === 'moreAnalytics' ) {

            array_unshift( $links, array(
                'reportId'    => 'domstreams',
                'label'       => 'Recordings',
                'description' => 'pointer, scroll and click recordings of this page.',
                'params'      => array( 'pagePath' => '{pagePath}' ),
            ) );
        }

        if ( $reportId === 'content' && $widgetId === 'related' ) {

            array_unshift( $links, array( 'reportId' => 'domstreams', 'label' => 'Recordings' ) );
        }

        return $links;
    }

    function registerReports() {

        $this->registerReport( 'domstreams', array( 'controller' => 'domstream.reportDomstreams' ) );
    }

    function registerNavigation() {

        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'domstreams' ), 'Recordings', 7 );
    }

    function registerApiMethods() {

        $this->registerRestApiRoute( 'v1', 'domstreams', 'GET',
            'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController', '' );
    }
}

?>
