<?php
namespace OWA\Module\Domstream;

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
 *
 * @phpstan-import-type Definition from \OWA\Module\Base\Classes\TrackingEventHelpers
 * @phpstan-import-type Config from \OWA\Module\Base\Classes\TrackerBundle
 */
class Module extends \OWA\Core\Module {

    /** The event name the recorder sends: dispatched as tracking.domstream. */
    const EVENT_NAME = 'domstream';

    /**
     * The setting that turns on each feature this module adds to a Profile's
     * tracking bundle, as TrackerBundle::FEATURE_SETTINGS does for Base's.
     */
    const FEATURE_SETTINGS = [
        'record' => 'trackDomStream',
    ];

    public function __construct() {

        $this->name = 'domstream';
        $this->display_name = 'Domstream';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '2.0';
        $this->description = 'Records the pointer, scroll, clicks and key presses on a page, for playback.';
        $this->config_required = false;
        $this->required_schema_version = 3;

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
     * @return array<string, Definition> property name => definition
     */
    public static function trackingProperties() {

        $properties = [];

        foreach ( [
            'recording_id'  => 'string',
            'seq'           => 'integer',
            'page_view_seq' => 'integer',
            'offset_ms'     => 'integer',
            'duration_ms'   => 'integer',
            'viewport_w'    => 'integer',
            'viewport_h'    => 'integer',
            'samples'       => 'json',
        ] as $name => $type ) {

            $properties[ $name ] = [
                'set_by'    => 'client',
                'from'      => [ $name ],
                'events'    => [ self::EVENT_NAME ],
                'data_type' => $type,
                'required'  => false,
                'callbacks' => [],
            ];
        }

        return $properties;
    }

    public function registerActions() {

        // Its tag settings, beside Base's on every Tracking Tag screen (PLAN 2.24.4).
        $this->registerSettingsFieldSet( [
            'id'       => 'trackingTag',
            'group'    => 'tracking_tag',
            'legend'   => \OWA\Core\CoreAPI::t( 'Page interaction recording' ),
            'description' => \OWA\Core\CoreAPI::t( 'Pointer movement, clicks and key presses, recorded so a visit can be played back.' ),
            'settings' => [ 'record', 'sample_rate' ],
        ] );

        $this->registerAction( 'domstream.processEvent',
            'OWA\\Module\\Domstream\\Controller\\ProcessEvent' );
        $this->registerAction( 'domstream.reportDomstreams',
            'OWA\\Module\\Domstream\\Controller\\ReportDomstreams' );
    }

    public function _registerEntities() {

        $this->registerEntity( [ 'domstream_chunk', 'domstream_payload' ] );
    }

    /** A chunk goes to this module's processor, never to Base's. */
    public function _registerEventProcessors() {

        $this->addTrackingEventProcessor( self::EVENT_NAME, 'domstream.processEvent' );
    }

    public function registerFilters() {

        $this->registerFilter( 'tracker_bundle_config', $this, 'addToBundle', 99 );
        $this->registerFilter( 'tracker_feature_labels', $this, 'labelFeatures', 10 );
        $this->registerFilter( 'report_row_actions', $this, 'addRowActions', 10 );
    }

    /**
     * A Profile's tracking bundle records when the Profile says so (PLAN 2.24):
     * the sample rate among its options, trackDomStream among its features, and
     * the recorder's chunk inlined.
     *
     * @param  Config $config  options, features, plugins
     * @param  string $site_id
     * @return Config
     */
    public function addToBundle( $config, $site_id = '' ) {

        $setting = function ( $key ) use ( $site_id ) {

            return \OWA\Core\CoreAPI::getSetting( 'domstream', $key, 'profile', (string) $site_id );
        };

        $on = array_filter( self::FEATURE_SETTINGS, function ( $command, $key ) use ( $setting ) {

            return (bool) $setting( $key );
        }, ARRAY_FILTER_USE_BOTH );

        if ( ! $on ) {

            return $config;
        }

        $config['options'][] = [ 'setDomstreamSampleRate', (int) $setting( 'sample_rate' ) ];

        foreach ( $on as $command ) {

            $config['features'][] = [ $command ];
        }

        $config['plugins'][] = 'domstream';

        return $config;
    }


    /**
     * The Tracking Tag screen's name for what addToBundle() turns on.
     *
     * @param  array<string, string> $labels command => label
     * @return array<string, string>
     */
    public function labelFeatures( $labels ) {

        $labels['trackDomStream'] = 'Page interaction recording';

        return $labels;
    }

    /**
     * A page row's recordings: the Recordings report filtered to its path.
     *
     * @param  array<array<string, mixed>> $actions name => action, as ConfiguredReport::ROW_ACTIONS
     * @return array<array<string, mixed>>
     */
    public function addRowActions( $actions ) {

        $actions['recordings'] = [
            'label'    => \OWA\Core\CoreAPI::t( 'Recordings' ),
            'description' => "Recordings, filtered to the page's path.",
            'reportId' => 'domstreams',
            'params'   => [ 'pagePath' => 'pagePath' ],
        ];

        return $actions;
    }

    public function registerReports() {

        $this->registerReport( 'domstreams', [
            'controller'  => 'domstream.reportDomstreams',
            'description' => 'Session recordings, one row per recording and newest first, with its length and its counts of clicks and key presses.',
        ] );
    }

    public function registerNavigation() {

        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'domstreams' ), \OWA\Core\CoreAPI::t( 'Recordings' ), 7 );
    }

    public function registerApiMethods() {

        $this->registerRestApiRoute( 'v1', 'domstreams', 'GET',
            'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController' );
    }
}
