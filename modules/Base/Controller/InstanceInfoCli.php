<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * One report describing the whole instance: `php cli.php cmd=instance-info`.
 *
 * WHY THIS EXISTS. Everything it reports could already be found out, and that
 * was the problem -- it took eight commands, two SQL queries and knowing which
 * settings to read, so nobody did it, and the things that fail QUIETLY are
 * exactly the things nobody went looking for. A stale user-agent database does
 * not error, it misattributes. An un-run scheduler does not error, it does
 * nothing. Retention that was never configured does not error, it accumulates.
 *
 * WHAT IT IS NOT. It changes nothing and it is safe to run on a live instance
 * at any time. Every query here is a count or a metadata read.
 *
 * It exits 0 whether or not it finds problems, because it is an INFO command
 * and its name says so. Pass `strict=1` to exit non-zero when anything is in a
 * FAIL state, which is what makes it usable from monitoring.
 */
class InstanceInfoCli extends \OWA\Core\Controller\Cli {

    /** Status ranks, worst last -- the summary reports the worst seen. */
    const OK   = 'ok';
    const WARN = 'warn';
    const FAIL = 'fail';

    /**
     * Column at which values line up. Wide enough for the longest label used,
     * which is an indented owa_commerce_transaction_fact.
     */
    const LABEL_WIDTH = 32;

    /** @var bool */
    private $colour = false;

    /** @var array<string,int> */
    private $tally = array( self::OK => 0, self::WARN => 0, self::FAIL => 0 );

    function __construct( $params ) {

        /*
         * The same gate the other administrative CLI commands use. It reports
         * configuration, module state and row counts, which is administrator
         * information even though it changes nothing.
         */
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $this->colour = $this->useColour();

        $lines = array();

        $lines = array_merge( $lines, $this->heading() );
        $lines = array_merge( $lines, $this->section( 'Environment',  $this->environment() ) );
        $lines = array_merge( $lines, $this->section( 'Modules',      $this->modules() ) );
        $lines = array_merge( $lines, $this->section( 'Schema',       $this->schema() ) );
        $lines = array_merge( $lines, $this->section( 'Scheduler',    $this->scheduler() ) );
        $lines = array_merge( $lines, $this->section( 'Fact tables',  $this->factTables() ) );
        $lines = array_merge( $lines, $this->section( 'Freshness',    $this->freshness() ) );
        $lines = array_merge( $lines, $this->section( 'Event queue',  $this->queue() ) );
        $lines = array_merge( $lines, $this->section( 'Contents',     $this->contents() ) );
        $lines = array_merge( $lines, $this->summary() );

        $this->write( $lines );

        if ( $this->getParam( 'strict' ) && $this->tally[ self::FAIL ] ) {

            return $this->fail( sprintf( '%d check(s) failed.', $this->tally[ self::FAIL ] ) );
        }
    }

    // ---------------------------------------------------------------- sections

    /**
     * Who this instance is. No status marks: these are facts, not judgements.
     */
    private function heading() {

        $url = \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ) ?: '(no public url set)';

        $rows = array(
            $this->fact( 'Version',   OWA_VERSION ),
            $this->fact( 'Location',  OWA_DIR ),
            $this->fact( 'Public URL', $url ),
            $this->fact( 'Database',  $this->databaseName() ),
            $this->fact( 'PHP',       phpversion() . ' (' . PHP_OS . ')' ),
            $this->fact( 'Timezone',  $this->timezone() ),
            $this->fact( 'Reported',  date( 'Y-m-d H:i:s' ) ),
        );

        return array_merge(
            array( '', $this->bold( 'Open Web Analytics -- instance info' ), '' ),
            $rows );
    }

    /**
     * The same questions the installer asks, from the same definition.
     */
    private function environment() {

        $rows = array();

        foreach ( \OWA\Module\Base\Classes\EnvironmentCheck::all(
                      $this->c->get( 'base', 'config_file' ) ) as $check ) {

            $rows[] = $this->row(
                $check['passed'] ? self::OK : self::FAIL,
                $check['name'], $check['value'], $check['msg'] );
        }

        // The error log has to stay writable by BOTH the web user and whoever
        // runs the CLI. When it is not, a notice becomes a fatal, because a log
        // write that cannot open its file raises.
        $log = \OWA\Core\CoreAPI::getSetting( 'base', 'error_log_file' );

        if ( $log ) {

            $exists   = file_exists( $log );
            $writable = $exists ? is_writable( $log ) : is_writable( dirname( $log ) );

            $rows[] = $this->row(
                $writable ? self::OK : self::FAIL,
                'Error log',
                $exists ? ( $writable ? 'writable' : 'NOT writable' ) : 'not created yet',
                'Make ' . $log . ' writable by both the web server user and the CLI user '
              . '(0664). A log write that cannot open its file turns a notice into a fatal.' );
        }

        return $rows;
    }

    /**
     * Which modules are switched on, and which are merely present.
     *
     * An inactive module is the answer to "why is geolocation not working" more
     * often than any setting is: it is on disk, it looks installed, and it is
     * simply switched off. Being active is not a pass or a fail, so these carry
     * no status mark -- they are inventory.
     */
    private function modules() {

        $rows = array();

        $active  = (array) \OWA\Core\CoreAPI::getActiveModules();
        $present = (array) \OWA\Core\CoreAPI::getPresentModules();

        $rows[] = $this->fact( 'Active',
            $active ? implode( ', ', $active ) : 'none' );

        $inactive = array();

        foreach ( $present as $dir ) {

            $is_active = false;

            foreach ( $active as $name ) {

                if ( \OWA\Core\Lib::moduleDirName( $name ) === $dir ) {

                    $is_active = true;
                    break;
                }
            }

            if ( ! $is_active ) {

                $inactive[] = $dir;
            }
        }

        $rows[] = $this->fact( 'Present but inactive',
            $inactive ? implode( ', ', $inactive ) : 'none' );

        return $rows;
    }

    /**
     * Whether each active module's schema is current.
     *
     * Separate from the module list because it is a different question with a
     * different answer type: which modules exist is inventory, whether their
     * tables match the code is a health check that stops the scheduler dead
     * when it fails.
     */
    private function schema() {

        $rows = array();

        $pending = (bool) \OWA\Core\CoreAPI::isUpdateRequired();

        /*
         * Ask each ACTIVE module, and name the ones that are behind. A single
         * "updates required" flag says an upgrade is needed but not by what, and
         * on an instance with several modules that is the question.
         */
        $behind = (array) \OWA\Core\CoreAPI::getModulesNeedingUpdates();

        foreach ( (array) \OWA\Core\CoreAPI::getActiveModules() as $name ) {

            $current = ! in_array( $name, $behind, true );

            /*
             * The STORED version, which is not the same question as the one the
             * verdict beside it answers. Module::getSchemaVersion() defaults an
             * absent value to 1, so a module that was never installed reads as
             * current; printing the raw setting instead rendered "(schema )".
             * Both are reported, because the gap between them IS the finding.
             */
            $stored = \OWA\Core\CoreAPI::getSetting( $name, 'schema_version' );

            $rows[] = $this->row(
                $current ? self::OK : self::FAIL,
                $name,
                sprintf( '%s (schema %s)',
                    $current ? 'up to date' : 'UPDATE REQUIRED',
                    $stored ? (string) $stored : 'not recorded' ),
                "Run 'php cli.php cmd=update'. Until you do, the job scheduler "
              . 'refuses every job.' );

            /*
             * Active, but never installed.
             *
             * install() is what creates a module's tables and records its
             * version; activate() only sets is_active. A module enabled by the
             * older cmd=activate therefore runs with no tables and no version,
             * and nothing says so -- the default of 1 makes it read as current.
             * Harmless while a module has no updates and no entities, which is
             * why it has gone unnoticed; the first update such a module ships
             * will run against tables that were never created.
             */
            if ( ! $stored ) {

                $rows[] = $this->row( self::WARN, '  ' . $name,
                    'active, but never installed',
                    sprintf( 'No schema version was recorded, so this module was activated '
                           . 'without being installed and its tables may never have been '
                           . "created. Run 'php cli.php cmd=activate module=%s' to install "
                           . 'it properly; that is safe to run on an installed module.',
                             $name ) );
            }
        }

        if ( ! $rows ) {

            $rows[] = $this->row(
                $pending ? self::FAIL : self::OK,
                'Modules',
                $pending ? 'UPDATE REQUIRED' : 'up to date',
                "Run 'php cli.php cmd=update'." );
        }

        return $rows;
    }

    /**
     * Nothing periodic happens without the cron entry, and nothing says so.
     */
    private function scheduler() {

        $rows = array();

        $enabled = (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' );

        $rows[] = $this->row(
            $enabled ? self::OK : self::WARN,
            'Enabled', $enabled ? 'yes' : 'no (OWA_SCHEDULER_ENABLED is off)',
            'No job will run while the scheduler is disabled.' );

        $problem = \OWA\Module\Base\Classes\SchedulerHealth::problem();

        $rows[] = $this->row(
            $problem ? self::FAIL : self::OK,
            'Cron entry',
            $problem ? 'not running' : 'running',
            'Add this line to the crontab of the user that owns the OWA files:  '
          . \OWA\Module\Base\Classes\SchedulerHealth::cronLine() );

        $jobs  = $this->registeredJobs();
        $state = $this->jobState();

        $rows[] = $this->fact( 'Jobs registered', (string) count( $jobs ) );

        foreach ( $jobs as $name => $job ) {

            $row = isset( $state[ $name ] ) ? $state[ $name ] : null;

            if ( ! $row ) {

                $rows[] = $this->row( self::WARN, '  ' . $name, 'never run',
                    'The dispatcher has not reached this job yet.' );

                continue;
            }

            $failures = (int) ( $row['failure_count'] ?? 0 );
            $last     = (int) ( $row['last_run_at'] ?? 0 );

            $rows[] = $this->row(
                $failures ? self::WARN : self::OK,
                '  ' . $name,
                sprintf( '%s, last run %s%s',
                    (string) ( $row['last_status'] ?? 'unknown' ),
                    $last ? date( 'Y-m-d H:i', $last ) : 'never',
                    $failures ? sprintf( ', %d failure(s)', $failures ) : '' ),
                "See 'php cli.php cmd=schedule-status' for the detail." );
        }

        return $rows;
    }

    /**
     * Partitioning, and whether anything ever deletes.
     */
    private function factTables() {

        $rows   = array();
        $counts = $this->partitionCounts();

        if ( $counts === null ) {

            return array( $this->row( self::WARN, 'Partitioning', 'could not be read',
                'Partition metadata was not readable. This needs MySQL 5.7 or later.' ) );
        }

        $partitioned = count( array_filter( $counts ) );
        $total       = count( $counts );

        $rows[] = $this->row(
            $partitioned ? self::OK : self::WARN,
            'Partitioned tables',
            sprintf( '%d of %d', $partitioned, $total ),
            "Not an error. Run 'php cli.php cmd=partition-init' to partition them, or "
          . 'ignore this if the instance is small.' );

        foreach ( $counts as $table => $n ) {

            $rows[] = $this->fact( '  ' . $table,
                $n ? sprintf( '%d partition(s)', $n ) : 'not partitioned' );
        }

        /*
         * Retention is opt-in and silent. rotate-partitions EXTENDS the tables
         * and merges old periods; it deletes nothing unless it is given a
         * 'keep'. An instance that wanted a retention window and never set one
         * looks identical to an instance that wanted to keep everything.
         */
        $jobs = $this->registeredJobs();
        $keep = $jobs['rotate-partitions']['params']['keep'] ?? null;

        $rows[] = $this->row(
            $keep ? self::OK : self::WARN,
            'Retention',
            $keep ? sprintf( 'keeping %d month(s)', (int) $keep ) : 'unlimited (nothing is deleted)',
            'Not an error, and a reasonable default -- but it is a choice. Set a '
          . "'keep' param on the rotate-partitions job in owa-config.php to bound it." );

        return $rows;
    }

    /**
     * The two datasets that rot without erroring.
     */
    private function freshness() {

        $rows = array();

        $dir  = \OWA\Core\CoreAPI::getSetting( 'base', 'ua_regexes_dir' ) ?: OWA_DATA_DIR . 'ua-parser/';
        $file = $dir . 'regexes.php';

        if ( file_exists( $file ) ) {

            $age = (int) floor( ( time() - filemtime( $file ) ) / 86400 );

            $rows[] = $this->row(
                $age > 180 ? self::WARN : self::OK,
                'User-agent patterns',
                sprintf( 'updated %s (%d day(s) ago)', date( 'Y-m-d', filemtime( $file ) ), $age ),
                "Run 'php cli.php cmd=update-ua-regexes'. Stale patterns do not error -- "
              . 'new browsers and crawlers are just counted as something else.' );

        } else {

            $rows[] = $this->row( self::WARN, 'User-agent patterns',
                'never updated (using the bundled copy)',
                "Run 'php cli.php cmd=update-ua-regexes'. The bundled patterns are as old "
              . "as the PHP library's last release, so updating the dependency does not help." );
        }

        $geo = $this->geoipStatus();

        if ( $geo ) {

            $rows[] = $geo;
        }

        return $rows;
    }

    /**
     * A queue that stops draining accumulates in silence.
     */
    private function queue() {

        $rows = array();

        $item = \OWA\Core\CoreAPI::entityFactory( 'base.queue_item' );
        $table = $item->getTableName();

        $total = $this->countOf( sprintf( 'SELECT COUNT(*) AS n FROM %s', $table ) );

        if ( $total === null ) {

            return array( $this->fact( 'Queued events', 'table not present' ) );
        }

        $rows[] = $this->row(
            $total > 10000 ? self::WARN : self::OK,
            'Queued events', (string) $total,
            'A queue this size usually means the drain stopped. Check '
          . "'php cli.php cmd=processEventQueue' and the error log." );

        $oldest = $this->countOf( sprintf(
            'SELECT MIN(not_before_timestamp) AS n FROM %s', $table ) );

        if ( $total && $oldest ) {

            $rows[] = $this->fact( '  oldest', date( 'Y-m-d H:i', (int) $oldest ) );
        }

        return $rows;
    }

    /**
     * What the instance actually holds.
     */
    private function contents() {

        $rows = array();

        foreach ( array(
            'Organizations' => 'base.organization',
            'Properties'    => 'base.property',
            'Sites'         => 'base.site',
            'Users'         => 'base.user',
        ) as $label => $entity ) {

            $n = null;

            try {

                $e = \OWA\Core\CoreAPI::entityFactory( $entity );
                $n = $this->countOf( sprintf( 'SELECT COUNT(*) AS n FROM %s', $e->getTableName() ) );

            } catch ( \Throwable $e ) {

                $n = null;
            }

            $rows[] = $this->fact( $label, $n === null ? 'not available' : (string) $n );
        }

        return $rows;
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Partition count per fact table, or null when the metadata is unreadable.
     *
     * Through the dialect's listPartitions() rather than a query written here:
     * schema introspection is backend-specific, and a spelling that only works
     * on MySQL would be invisible until someone ran OWA on something else.
     *
     * @return array<string,int>|null
     */
    private function partitionCounts() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        // A backend with no partitioning at all is a fact about the backend,
        // not a fault in this instance -- say so rather than guessing zero.
        if ( ! is_object( $db ) || ! method_exists( $db, 'listPartitions' ) ) {

            return null;
        }

        $tables = array(
            'request', 'session', 'click', 'domstream',
            'action_fact', 'commerce_transaction_fact', 'commerce_line_item_fact',
        );

        $counts = array();

        foreach ( $tables as $short ) {

            try {

                $e = \OWA\Core\CoreAPI::entityFactory( 'base.' . $short );

            } catch ( \Throwable $ex ) {

                continue;
            }

            $table = $e->getTableName();

            try {

                $parts = $db->listPartitions( $table );

            } catch ( \Throwable $ex ) {

                return null;
            }

            $counts[ $table ] = count( (array) $parts );
        }

        return $counts;
    }

    /**
     * The GeoIP database, when the module that uses it is active.
     */
    private function geoipStatus() {

        if ( ! \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'is_active' ) ) {

            return null;
        }

        $file = OWA_DATA_DIR . 'maxmind/GeoLite2-City.mmdb';

        if ( ! file_exists( $file ) ) {

            return $this->row( self::WARN, 'GeoIP database', 'not downloaded',
                "Run 'php cli.php cmd=update-geoip-db'. Needs a MaxMind licence key." );
        }

        $age = (int) floor( ( time() - filemtime( $file ) ) / 86400 );

        return $this->row(
            $age > 60 ? self::WARN : self::OK,
            'GeoIP database',
            sprintf( 'updated %s (%d day(s) ago)', date( 'Y-m-d', filemtime( $file ) ), $age ),
            "Run 'php cli.php cmd=update-geoip-db'. A stale database does not fail; it "
          . 'answers, and the reports look normal.' );
    }

    /**
     * The jobs the scheduler knows about.
     *
     * Through the service rather than the raw setting: a job can be registered
     * by a module as well as declared in owa-config.php, and reading the setting
     * alone reports zero jobs on an instance that is running several.
     *
     * @return array<string,array>
     */
    private function registeredJobs() {

        try {

            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $s->loadCliCommands();
            $s->loadJobs();

            return (array) $s->getJobs();

        } catch ( \Throwable $e ) {

            return array();
        }
    }

    /**
     * Scheduler state rows, keyed by job name.
     *
     * @return array<string,array>
     */
    private function jobState() {

        $out = array();

        try {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' );
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $rows = $db->get_results( sprintf( 'SELECT * FROM %s', $e->getTableName() ) );

            foreach ( (array) $rows as $row ) {

                $out[ (string) $row['job_name'] ] = $row;
            }

        } catch ( \Throwable $ex ) {

            return array();
        }

        return $out;
    }

    /**
     * A single numeric answer, or null when the query could not run.
     *
     * Db::query() swallows errors and returns falsy, so "0" and "the table is
     * not there" are the same value unless they are told apart here.
     *
     * @return int|null
     */
    private function countOf( $sql ) {

        try {

            $db  = \OWA\Core\CoreAPI::dbSingleton();
            $row = $db->get_row( $sql );

        } catch ( \Throwable $e ) {

            return null;
        }

        if ( ! is_array( $row ) || ! array_key_exists( 'n', $row ) || $row['n'] === null ) {

            return null;
        }

        return (int) $row['n'];
    }

    private function databaseName() {

        $name = \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' );
        $host = \OWA\Core\CoreAPI::getSetting( 'base', 'db_host' );

        return $name ? $name . ( $host ? ' @ ' . $host : '' ) : '(not configured)';
    }

    private function timezone() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'timezone' ) ?: date_default_timezone_get();
    }

    // ------------------------------------------------------------- formatting

    /**
     * A row with a status mark, counted towards the summary.
     */
    private function row( $status, $label, $value, $remedy = '' ) {

        $this->tally[ $status ]++;

        $line = sprintf( '  %s  %s%s',
            $this->mark( $status ),
            $this->pad( $label ),
            $this->paint( $value, $status ) );

        if ( $status !== self::OK && $remedy ) {

            // Under the value, indented past the mark, so the eye can skip it.
            $line .= PHP_EOL . '        ' . $this->dim( $this->wrap( $remedy, 8 ) );
        }

        return $line;
    }

    /**
     * A row with no judgement attached -- a number or a name.
     */
    private function fact( $label, $value ) {

        return sprintf( '     %s%s', $this->pad( $label ), $value );
    }

    private function section( $title, $rows ) {

        if ( ! $rows ) {

            return array();
        }

        return array_merge( array( '', $this->bold( strtoupper( $title ) ) ), $rows );
    }

    private function summary() {

        $bad  = $this->tally[ self::FAIL ];
        $warn = $this->tally[ self::WARN ];

        if ( $bad ) {

            $verdict = $this->paint( sprintf( '%d problem(s) need fixing', $bad ), self::FAIL );

        } elseif ( $warn ) {

            $verdict = $this->paint( sprintf( '%d thing(s) worth a look', $warn ), self::WARN );

        } else {

            $verdict = $this->paint( 'Everything checks out', self::OK );
        }

        return array(
            '',
            str_repeat( '-', 64 ),
            sprintf( '  %s   (%d ok, %d warning(s), %d failure(s))',
                $verdict, $this->tally[ self::OK ], $warn, $bad ),
            '',
        );
    }

    private function pad( $label ) {

        $width = self::LABEL_WIDTH;

        return $label . str_repeat( ' ', max( 1, $width - strlen( $label ) ) );
    }

    /**
     * Wrap a remedy so a long one does not run off the terminal.
     */
    private function wrap( $text, $indent ) {

        return str_replace( "\n", PHP_EOL . str_repeat( ' ', $indent ),
            wordwrap( $text, 72 - $indent, "\n", false ) );
    }

    private function mark( $status ) {

        $glyphs = array( self::OK => '+', self::WARN => '!', self::FAIL => 'x' );

        return $this->paint( $glyphs[ $status ], $status );
    }

    /**
     * Colour, but only when a person is looking at it.
     *
     * Piped or redirected output gets none: escape codes in a log file or a
     * monitoring check are noise that the reader cannot turn off.
     */
    private function useColour() {

        if ( $this->getParam( 'no-color' ) || getenv( 'NO_COLOR' ) !== false ) {

            return false;
        }

        if ( ! defined( 'STDOUT' ) ) {

            return false;
        }

        return function_exists( 'stream_isatty' ) ? @stream_isatty( STDOUT ) : false;
    }

    private function paint( $text, $status ) {

        if ( ! $this->colour ) {

            return $text;
        }

        $codes = array( self::OK => '0;32', self::WARN => '0;33', self::FAIL => '0;31' );

        return "\033[" . $codes[ $status ] . 'm' . $text . "\033[0m";
    }

    private function bold( $text ) {

        return $this->colour ? "\033[1m" . $text . "\033[0m" : $text;
    }

    private function dim( $text ) {

        return $this->colour ? "\033[2m" . $text . "\033[0m" : $text;
    }
}
