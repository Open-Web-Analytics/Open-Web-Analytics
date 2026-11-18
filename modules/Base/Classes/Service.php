<?php
namespace OWA\Module\Base\Classes;

/**
 * Service Class
 * 
 *
 * @phpstan-import-type JobRegistration from \OWA\Core\Module
 * @phpstan-type MetricDefinition array<string, mixed>
 * @phpstan-type RestRoute array{class_name: string, conf: array<string, mixed>}
 */


class Service extends \OWA\Core\Base {

    /** @var bool */
    public $init = false;
    /** @var \OWA\Core\RequestContainer|null */
    public $request;
    /** @var mixed nothing sets it; getState() answers the request's */
    public $state;
    /** @var \OWA\Module\Base\Classes\ServiceUser|null */
    public $current_user;
    /** @var mixed nothing sets it */
    public $settings;
    /** @var array<string, mixed> map name => its values, name => value */
    public $maps = [];
    /** @var bool */
    public $update_required = false;
    /** @var bool */
    public $install_required = false;
    /** @var list<string> */
    public $modules_needing_updates = [];
    /** @var array<string, \OWA\Core\Module> name => module */
    public $modules = [];
    /** @var array<string, string> entity name => module.entity */
    public $entities = [];
    /** @var array<string, list<MetricDefinition>> metric name => its implementations */
    public $metrics = [];
    /** @var array<string, array<string, array<string, mixed>>> name => entity => registration */
    public $denormalizedDimensions = [];
    /** @var object|null an injected user-agent parser */
    public $browscap;
    /** @var \OWA\Module\Base\Classes\Geolocation|null */
    public $geolocation;
    /** @var array<string, array<string, array<string, array<string, RestRoute>>>> module => version => route => request method => route */
    public $restApiRoutes = [];

    public function __construct() {
    }

    /**
     * @return void
     */
    public function initializeFramework() {

        if (!$this->isInit()) {

            // setup request container
            $this->request = \OWA\Core\CoreAPI::requestContainerSingleton();

            $this->_loadModules();
            $this->_loadFilters();
            $this->_loadEntities();
            $this->_loadMetrics();
            $this->_loadDimensions();
            $this->_loadEventProcessors();
            $this->setInit();

            // setup current user
            $this->current_user = new \OWA\Module\Base\Classes\ServiceUser();
            // the 'log_users' config directive relies on this being populated
            $this->current_user->setUserData( 'user_id' ,  $this->request->state->get('u') );
            // load geolocation obj.
            $this->geolocation = \OWA\Module\Base\Classes\Geolocation::getInstance();
        }

    }

    /**
     * An explicitly injected parser, which outranks the memo. Handing null
     * CLEARS the memo, which is what a caller processing a second event with a
     * different agent needs.
     *
     * @param object|null $b
     * @return void
     */
    public function setBrowscap( $b ) {

        if ( $b === null ) {

            $this->browscap        = null;
            $this->browscap_by_ua  = [];

            return;
        }

        $this->browscap = $b;
    }

    /** @var array<string,object> user agent => its parse */
    private $browscap_by_ua = [];

    /**
     * The user-agent parse, memoised PER AGENT.
     *
     * It used to memoise the first parse a process made and then ignore every
     * $ua it was given afterwards -- so a queue drain, which walks many events in
     * one process, versioned all of them by whichever agent it happened to see
     * first. Every reading came from that one parse: browser, browser_version,
     * os, os_version and the three device columns.
     *
     * Keyed by the agent instead. One parse per distinct agent, which is the
     * memo the callers were promised: a single request still parses once however
     * many properties ask, and a drain parses once per distinct visitor agent
     * rather than once, wrongly.
     *
     * @param  string $ua  the agent to parse; the request's own when empty
     * @return object
     */
    public function getBrowscap( $ua = '' ) {

        // An injected parser wins, so a test can hand over a stub.
        if ( ! empty( $this->browscap ) ) {

            return $this->browscap;
        }

        if ( ! $ua ) {

            $ua = $this->request->getServerParam( 'HTTP_USER_AGENT' );
        }

        $key = (string) $ua;

        if ( ! isset( $this->browscap_by_ua[ $key ] ) ) {

            $this->browscap_by_ua[ $key ] =
                \OWA\Core\CoreAPI::supportClassFactory( 'base', 'browscap', $ua );
        }

        return $this->browscap_by_ua[ $key ];
    }

    /**
     * @return void
     */
    public function _loadModules() {

        $present_modules = \OWA\Core\CoreAPI::getPresentModules();
        $am = \OWA\Core\CoreAPI::getActiveModules();

        /*
         * Not asked on the tracking path (log.php boots with tracking_mode):
         * a pending update stops nothing there -- only admin screens and the
         * scheduler act on one -- so the answer would be thrown away.
         */
        $check_updates = ! \OWA\Core\CoreAPI::getSetting( 'base', 'tracking_mode' );

        foreach ($am as $k => $v) {
            
            // active-module names are lowercase runtime names; getPresentModules()
            // returns on-disk dir names, which are PascalCase (PSR-4). Translate the
            // runtime name to its dir name for the presence check.
            if ( in_array( \OWA\Core\Lib::moduleDirName( $v ), $present_modules ) ) {
                $m = \OWA\Core\CoreAPI::moduleClassFactory($v);
    
                $this->addModule($m);
    
                if ( $check_updates && ! $m->isUpToDate() ) {

                    $this->markModuleAsNeedingUpdate($m->name);
                }
            }
        }

        // set schema update flag
        if (!empty($this->modules_needing_updates)) {
            $this->setUpdateRequired();
        }
    }
    
    /**
     * @return void
     */
    public function _loadEntities() {

        foreach ($this->modules as $k => $module) {

            foreach ($module->entities as $entity_k => $entity_v) {
                // TODO: remove this to make API stateless
                //$this->entities[] = $module->name.$entity_v;
                // proper call
                $this->addEntity($entity_v, $module->name.'.'.$entity_v);
            }
        }

        return;
    }

    /**
     * @return void
     */
    public function _loadFilters() {

        foreach ($this->modules as $k => $module) {

            $module->registerFilters();
        }
    }

    /**
     * @return void
     */
    public function _loadMetrics() {

        foreach ($this->modules as $k => $module) {

            if (is_array($module->metrics)) {

                $this->metrics = array_merge_recursive( $this->metrics, $module->metrics);
            }
        }
    }

    /**
     * @return array<string, list<MetricDefinition>> metric name => its implementations
     */
    public function getAllMetrics() {

        return $this->metrics;
    }

    /**
     * @return void
     */
    public function loadCliCommands() {

        $command_map = [];

        foreach ($this->modules as $k => $module) {

            if (is_array($module->cli_commands)) {
                $command_map = array_merge($command_map, $module->cli_commands);
            }
        }

        $this->setMap('cli_commands', $command_map);
    }

    /**
     * Commands that must never be scheduled, however they are named, and why.
     *
     * Checked against the COMMAND rather than the job name, so a friendly label
     * cannot smuggle one past.
     */
    const NEVER_SCHEDULE = [
        'install'       => 'Re-runs schema installation.',
        'update'        => 'Changes the schema underneath the running process.',
        'reset-secrets' => 'Rewrites owa-config.php.',
    ];

    /**
     * The keys an OWA_SCHEDULED_JOBS entry may give, and what each means.
     * applyConfiguredJobs() copies exactly these onto the job.
     */
    const SCHEDULED_JOB_KEYS = [
        'command'     => 'The CLI command to run. Required for a job OWA does not register itself.',
        'schedule'    => 'A cron expression, an `@` alias, or `off`. Required for a job OWA does not register itself.',
        'params'      => 'Arguments passed to the command, as `name => value` pairs: `array( \'days\' => 3 )` runs the command with `days=3`.',
        'description' => 'A sentence for the System Health screen and `schedule-status`.',
    ];

    /**
     * Build the job registry: what modules registered, overlaid with what
     * OWA_SCHEDULED_JOBS says.
     *
     * Idempotent, and called only by the scheduler commands and tests -- there
     * is no reason for every CLI invocation to build this map.
     *
     * A malformed entry disables THAT job and nothing else. A typo in one line
     * of config must never stop the other jobs running, so every rejection here
     * is per-entry and noticed by name.
     *
     * @return void
     */
    public function loadJobs() {

        // The merge validates commands, so the command map must be built first.
        // Both are idempotent.
        $this->loadCliCommands();

        $jobs = [];

        foreach ($this->modules as $k => $module) {

            if (is_array($module->scheduled_jobs)) {
                $jobs = array_merge($jobs, $module->scheduled_jobs);
            }
        }

        $jobs = $this->applyConfiguredJobs( $jobs );

        $this->setMap('scheduled_jobs', $jobs);
    }

    /**
     * Overlay OWA_SCHEDULED_JOBS onto the registered jobs.
     *
     * Keyed by job name rather than a list of arrays each carrying a 'name', so
     * a name cannot be missing and PHP itself rejects two entries claiming the
     * same job -- a whole class of merge rule deleted rather than documented.
     *
     * Overrides are PER KEY: giving only 'params' keeps the registered schedule,
     * giving only 'schedule' keeps the registered params. Forcing an operator to
     * restate values they did not mean to change is how config drifts away from
     * code.
     *
     * @param array<string, JobRegistration> $jobs  registered jobs, keyed by name
     * @return array<string, JobRegistration>
     */
    protected function applyConfiguredJobs( $jobs ) {

        $configured = \OWA\Core\CoreAPI::getSetting( 'base', 'scheduled_jobs' );

        if ( ! is_array( $configured ) ) {

            return $jobs;
        }

        foreach ( $configured as $name => $spec ) {

            $name = trim( (string) $name );

            if ( $name === '' || ! is_array( $spec ) ) {

                \OWA\Core\CoreAPI::notice(
                    'OWA_SCHEDULED_JOBS: entries must be keyed by job name with an array value. Skipping one.'
                );

                continue;
            }

            // A misspelt key would leave the job on what it was registered
            // with, as if the entry were not there.
            $unknown = array_diff( array_map( 'strval', array_keys( $spec ) ), array_keys( self::SCHEDULED_JOB_KEYS ) );

            if ( $unknown ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'OWA_SCHEDULED_JOBS: "%s" has %s, which %s not one of: %s. Skipping the entry.',
                    $name, implode( ', ', $unknown ), count( $unknown ) === 1 ? 'is' : 'are',
                    implode( ', ', array_keys( self::SCHEDULED_JOB_KEYS ) )
                ) );

                continue;
            }

            $known = isset( $jobs[ $name ] );

            // A new job has nothing to inherit, so it must say what it runs and
            // when. Guessing the command from the key is exactly the ambiguity
            // separating name from command removes.
            if ( ! $known ) {

                foreach ( [ 'command', 'schedule' ] as $required ) {

                    if ( empty( $spec[ $required ] ) ) {

                        \OWA\Core\CoreAPI::notice( sprintf(
                            'OWA_SCHEDULED_JOBS: "%s" is not a registered job, so it needs a "%s". Skipping it.',
                            $name, $required
                        ) );

                        continue 2;
                    }
                }
            }

            $job = $known ? $jobs[ $name ] : [
                'name'        => $name,
                'module'      => 'config',
                'params'      => [],
                'description' => '',
            ];

            $job['source'] = $known ? 'config-override' : 'config';

            foreach ( array_keys( self::SCHEDULED_JOB_KEYS ) as $key ) {

                if ( isset( $spec[ $key ] ) ) {
                    $job[ $key ] = $key === 'params' ? (array) $spec[ $key ] : (string) $spec[ $key ];
                }
            }

            // A command nothing answers to would otherwise be listed as a
            // healthy job with a next-due time, and only fail silently at
            // dispatch. Refusing it here keeps the registry honest.
            if ( ! $this->getCliCommandClass( $job['command'] ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'OWA_SCHEDULED_JOBS: "%s" names command "%s", which is not registered. Skipping it.',
                    $name, $job['command']
                ) );

                continue;
            }

            if ( isset( self::NEVER_SCHEDULE[ $job['command'] ] ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'OWA_SCHEDULED_JOBS: "%s" runs %s, which must never be scheduled. Skipping it.',
                    $name, $job['command']
                ) );

                continue;
            }

            $jobs[ $name ] = $job;
        }

        return $jobs;
    }

    /**
     * @return array<string, JobRegistration>  every job, keyed by name
     */
    public function getJobs() {

        $jobs = $this->getMap('scheduled_jobs');

        return is_array( $jobs ) ? $jobs : [];
    }

    /**
     * @return void
     */
    public function _loadDimensions() {

        foreach ($this->modules as $k => $module) {

            // Recursive: dimensions are keyed name => entity => registration.
            if (is_array($module->denormalizedDimensions)) {

                $this->denormalizedDimensions = array_merge_recursive($this->denormalizedDimensions, $module->denormalizedDimensions);
            }
        }
    }

    /**
     * @return void
     */
    public function _loadEventProcessors() {

        $processors = [];

        foreach ($this->modules as $k => $module) {

            $processors = array_merge($processors, $module->event_processors);
        }

        $this->setMap('event_processors', $processors);

    }

    /**
     * @return \OWA\Module\Base\Classes\ServiceUser
     */
    public function getCurrentUser() {
        if (!$this->isInit()) {
            throw new \Exception('Current User Object could only be get if framework is initialized');
        }
        return $this->current_user;
    }

    /**
     * @return \OWA\Core\RequestContainer|null
     */
    public function getRequest() {

        return $this->request;
    }
    
    /**
     * @param  string $module
     * @param  string $version
     * @param  string $route_name
     * @param  string $request_method
     * @return RestRoute|void nothing when no route is registered
     */
    public function getRestApiRoute( $module, $version, $route_name, $request_method ) {
        
        if ( array_key_exists( $module, $this->restApiRoutes ) ) {

            if ( array_key_exists( $version, $this->restApiRoutes[$module] ) ) {
            
                if ( array_key_exists( $route_name, $this->restApiRoutes[$module][ $version ] ) ) {
                
                    if ( array_key_exists( $request_method, $this->restApiRoutes[$module][ $version ][ $route_name ] ) ) {
            
                        return $this->restApiRoutes[$module][ $version ][ $route_name ][ $request_method ] ;
                    }
                }	
            }
        }
    }
    
    /**
     * @param  string    $module
     * @param  string    $version
     * @param  string    $route_name
     * @param  string    $request_method
     * @param  RestRoute $value
     * @return void
     */
    public function setRestApiRoute( $module, $version, $route_name, $request_method, $value ) {
        
        $this->restApiRoutes[$module][$version][ $route_name ][ $request_method ] = $value;
    }
    
    /**
     * @return array<string, array<string, array<string, array<string, RestRoute>>>>
     */
    public function getAllRestApiRoutes() {
        
        return $this->restApiRoutes;
    }

    /**
     * @return \OWA\Module\Base\Classes\State
     */
    public function getState() {

        return $this->request->state;
    }

    /**
     * @param  string $map_name
     * @param  string $name
     * @return mixed the value; false when the map or the name is not there
     */
    public function getMapValue($map_name, $name) {

        if (array_key_exists($map_name, $this->maps)) {

            if ( $name && array_key_exists($name, $this->maps[$map_name])) {

                return $this->maps[$map_name][$name];
            } else {

                return false;
            }
        } else {

            return false;
        }
    }

    /**
     * @param  string $name
     * @return mixed|void the map; nothing when there is none
     */
    public function getMap($name) {

        if (array_key_exists($name, $this->maps)) {

            return $this->maps[$name];
        }

    }

    /**
     * @param  string $name
     * @param  mixed  $map  name => value
     * @return void
     */
    public function setMap($name, $map) {

        $this->maps[$name] = $map;
    }

    /**
     * @param  string $map_name
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setMapValue($map_name, $name, $value) {

        $this->maps[$map_name][$name] = $value;
    }

    /**
     * @return void
     */
    public function setUpdateRequired() {

        $this->update_required = true;
        return;
    }

    /**
     * @return bool
     */
    public function isUpdateRequired() {

        return $this->update_required;
    }

    /**
     * Whether a module's SCHEMA is behind: the narrower question the
     * scheduler asks. An update pending only because a new release has not
     * been applied (Base\Module::isUpToDate()) leaves the schema as the jobs
     * expect it, so they keep running.
     *
     * @return bool
     */
    public function isSchemaUpdateRequired() {

        foreach ( $this->modules as $module ) {

            if ( ! $module->isSchemaCurrent() ) {

                return true;
            }
        }

        return false;
    }

    /**
     * @param  \OWA\Core\Module $module
     * @return void
     */
    public function addModule($module) {

        $this->modules[$module->name] = $module;
    }

    /**
     * @param  string $name
     * @return void
     */
    public function markModuleAsNeedingUpdate($name) {

        $this->modules_needing_updates[] = $name;
    }

    /**
     * @return list<string>
     */
    public function getModulesNeedingUpdates() {

        return $this->modules_needing_updates;
    }


    /**
     * @return void
     */
    public function setInstallRequired() {
        $this->install_required = true;
    }

    /**
     * @param  string $entity_name
     * @param  string $class       module.entity
     * @return void
     */
    public function addEntity($entity_name, $class) {

        $this->entities[$entity_name] = $class;
    }

    /**
     * @return void
     */
    public function setInit() {
        $this->init = true;
    }

    /**
     * @return bool
     */
    public function isInit() {

        return $this->init;
    }

    /**
     * @param  string $name
     * @return \OWA\Core\Module|false
     */
    public function getModule($name) {

        if (array_key_exists($name, $this->modules)) {
            return $this->modules[$name];
        } else {
            return false;
        }

    }

    /**
     * @param  string $name
     * @return list<MetricDefinition>|void its implementations; nothing for a name no module registers
     */
    public function getMetricClasses($name) {

        if (array_key_exists($name, $this->metrics)) {

            return $this->metrics[$name];
        }
    }

    /**
     * A module's denormalized dimension. The modules' registries only: a
     * Property's custom dimensions are never here, and Classes\DimensionRegistry
     * is what a caller asks.
     *
     * @param  string $name
     * @param  string $entity
     * @return array<string, mixed>|void the registration; nothing when there is none
     */
    public function getDenormalizedDimension($name, $entity) {

        //print_r($this->denormalizedDimensions);
        if (array_key_exists($name, $this->denormalizedDimensions)) {
            if (array_key_exists($entity, $this->denormalizedDimensions[$name])) {
                return $this->denormalizedDimensions[$name][$entity];
            }
        }
    }

    /**
     * @param  string $command
     * @return mixed the class that runs it; false when none does
     */
    public function getCliCommandClass($command) {

        return $this->getMapValue('cli_commands', $command);
    }

}
