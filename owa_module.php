<?php

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
// $Id$
//

/**
 * Abstract Module Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

abstract class owa_module extends owa_base {

    /**
     * Name of module
     *
     * @var string
     */
    var $name;

    /**
     * Description of Module
     *
     * @var string
     */
    var $description;

    /**
     * Version of Module
     *
     * @var string
     */
    var $version;

    /**
     * Schema Version of Module
     *
     * @var string
     */
    //var $schema_version = 1;

    /**
     * Name of author of module
     *
     * @var string
     */
    var $author;

    /**
     * URL for author of module
     *
     * @var unknown_type
     */
    var $author_url;

    /**
     * Wiki Page title. Used to generate link to OWA wiki for this module.
     *
     * Must be unique or else it will could clobber another wiki page.
     *
     * @var string
     */
    var $wiki_title;

    /**
     * name used in display situations
     *
     * @var unknown_type
     */
    var $display_name;

    /**
     * Array of event names that this module has handlers for
     *
     * @var array
     */
    var $subscribed_events;

    /**
     * Array of link information for admin panels that this module implements.
     *
     * @var array
     */
    var $admin_panels;

    /**
     * Array of navigation links that this module implements
     *
     * @var unknown_type
     */
    var $nav_links;

    /**
     * Array of metric names that this module implements
     *
     * @var unknown_type
     */
    var $metrics;

    /**
     * Array of graphs that are implemented by this module
     *
     * @var array
     */
    var $graphs;

    /**
     * The Module Group that the module belongs to.
     *
     * This is used often to group a module's features or functions together in the UI
     *
     * @var string
     */
    var $group;

    /**
     * Array of Entities that are implmented by the module
     *
     * @var array
     */
    var $entities = array();

    /**
     * Required Schema Version
     *
     * @var array
     */
    var $required_schema_version;

    /**
     * Available Updates
     *
     * @var array
     */
    var $updates = array();

    /**
     * Event Processors Map
     *
     * @var array
     */
    var $event_processors = array();

    /**
     * Dimensions
     *
     * @var array
     */
    var $dimensions = array();

    /**
     * Dimensions
     *
     * @var array
     */
    var $denormalizedDimensions = array();

    /**
     *
     * @var array
     */
    var $formatters = array();

    /**
     * cli_commands
     *
     * @var array
     */
    var $cli_commands = array();

    /**
     * API Methods
     *
     * @var array
     */
    var $api_methods = array();

    /**
     * Background Jobs
     *
     * @var array
     */
    var $background_jobs = array();

    /**
     * Controllers
     *
     * @var array
     */
    var $actionControllers = array();

    /**
     * Update from CLI Required flag
     *
     * Used by controllers to see if an update error was becuase it needs
     * to be applied from the command line instead of via the browser.
     *
     * @var boolean
     */
    var $update_from_cli_required;
    
    /**
	 * Filesystem path of the module's directory
	 */
    var $path;

    /**
     * Constructor
     *
     *
     */
    function __construct() {
		
		$this->path = OWA_MODULES_DIR . $this->name . '/';
		
        parent::__construct();
		
		/**
		 * Initial registration calls
		 */
		$this->init();
		
        /**
         * Register Filters
         */
        //$this->registerFilters();

        /**
         * Register Metrics
         */
        $this->registerMetrics();

        /**
         * Register Dimensions
         */
        $this->registerDimensions();

        /**
         * Register CLI Commands
         */
        $this->registerCliCommands();

        /**
         * Register API Methods
         */
        $this->registerApiMethods();

        /**
         * Register Background Jobs
         */
        $this->registerBackgroundJobs();

        /**
         * Register Build Packages
         */
        $this->registerBuildPackages();

        $this->_registerEventHandlers();
        $this->_registerEventProcessors();
        $this->_registerEntities();
        $this->registerActions();

    }
    
    function init() {
	    
	    return false;
    }

    /**
     * Method for registering event processors
     *
     */
    function _registerEventProcessors() {

        return false;
    }

    /**
     * Returns array of admin Links for this module to be used in navigation
     *
     * @access public
     * @return array
     */
    function getAdminPanels() {

        return $this->admin_panels;
    }

    /**
     * Returns array of report links for this module that will be
     * used in report navigation
     *
     * @access public
     * @return array
     */
    function getNavigationLinks() {

        return $this->nav_links;
    }

    /**
     * Abstract method for registering event handlers
     *
     * Must be defined by a concrete module class for any event handlers to be registered
     *
     * @access public
     * @return array
     */
    function _registerEventHandlers() {

        return;
    }

    /**
     * Attaches an event handler to the event queue
     *
     * @param array $event_name
     * @param string $handler_name
     * @return boolean
     */
    function registerEventHandler($event_name, $handler_name, $method = 'notify', $dir = 'handlers') {

        if (!is_object($handler_name)) {

            //$handler = &owa_lib::factory($handler_dir,'owa_', $handler_name);
            $handler_name = owa_coreAPI::moduleGenericFactory($this->name, $dir, $handler_name, $class_suffix = null, $params = '', $class_ns = 'owa_');
        }

        $eq = owa_coreAPI::getEventDispatch();
        $eq->attach($event_name, array($handler_name, $method));
    }

    /**
     * Hooks a function to a filter
     *
     * @param array $event_name
     * @param string $handler_name
     * @return boolean
     */
    function registerFilter($filter_name, $handler_name, $method = '', $priority = 10, $dir = 'filters') {

        // if it's an object
        if ( is_object( $handler_name ) ) {

            owa_coreAPI::registerFilter($filter_name, array($handler_name, $method), $priority);

        // if it's a static method name
        } elseif ( strpos( $handler_name, '::') ) {

            owa_coreAPI::registerFilter($filter_name, $handler_name, $priority);

        // else try to create the class object
        } else {
            // create object
            if ( ! class_exists( $handler_name ) ) {

                //$handler = &owa_lib::factory($handler_dir,'owa_', $handler_name);
                $class = owa_coreAPI::moduleGenericFactory($this->name, $dir, $handler_name, $class_suffix = null, $params = '', $class_ns = 'owa_');
            }

            // register
            owa_coreAPI::registerFilter($filter_name, array($class, $method), $priority);
        }
    }

    /**
     * Attaches an event handler to the event queue
     *
     * @param array $event_name
     * @param string $handler_name
     * @return boolean
     * @depricated
     */
    function _addHandler($event_name, $handler_name) {

        return $this->registerEventHandler($event_name, $handler_name);

    }

    /**
     * Abstract method for registering administration/settings page
     *
     * @access public
     * @return array
     */
    function registerAdminPanels() {

        return;
    }

    /**
     * Registers an admin panel with this module
     *
     */
    function registerSettingsPanel($panel) {

        $this->admin_panels[] = $panel;

        return true;
    }

    /**
     * Registers an admin panel with this module
     * @depricated
     */
    function addAdminPanel($panel) {

        return $this->registerSettingsPanel($panel);
    }

    /**
     * Registers Group Link with a particular View
     * @DEPRICATED - use addNavigationSubGroup and addNavigationLinkInSubGroup
     */
    function addNavigationLink($group, $subgroup = '', $ref, $anchortext, $order = 0, $priviledge = 'view_reports') {

        if (!empty($subgroup)):
            $this->addNavigationLinkInSubGroup($subgroup,$ref, $anchortext, $order = 0, $priviledge ,$group);
        else:
            $this->addNavigationSubGroup($anchortext,$ref, $anchortext, $order = 0, $priviledge ,$group);
        endif;

        return;
    }

    /**
     * Adds a new Subgroup in the navigation
     *
     * @param string $subgroupName
     * @param string $ref
     * @param string $anchortext
     * @param integer $order
     * @param string $priviledge
     * @param string $groupName
     */
    public function addNavigationSubGroup($subgroupName, $ref, $anchortext, $order = 0, $priviledge = 'view_reports', $groupName = 'Reports', $icon_class = '') {
        $this->nav_links[$groupName][$subgroupName] = $this->getLinkStruct($ref, $anchortext, $order,$priviledge, $icon_class);
    }

    /**
     * Adds a new Link to an existing Subgroup in the navigation
     *
     * @param string $subgroupName
     * @param string $ref
     * @param string $anchortext
     * @param integer $order
     * @param string $priviledge
     * @param string $groupName
     */
    public function addNavigationLinkInSubGroup($subgroupName, $ref, $anchortext, $order = 0, $priviledge = 'view_reports', $groupName = 'Reports') {
        $this->nav_links[$groupName][$subgroupName]['subgroup'][] = $this->getLinkStruct($ref, $anchortext, $order,$priviledge);
    }

    /**
     * Abstract method for registering a module's entities
     *
     * This method must be defined in concrete module classes in order for entities to be registered.
     */
    function _registerEntities() {

        return false;
    }

    function registerNavigation() {

        return false;
    }


    /**
     * Registers an Entity
     *
     * Can take an array of entities or just a single entity as a string.
     * Will add an enetiy to the module's entity array. Required for entity installation, etc.
     *
     * @param $entity_name array or string
     */
    function registerEntity($entity_name) {

        if (is_array($entity_name)) {
            $this->entities = array_merge($this->entities, $entity_name);
        } else {
            $this->entities[] = $entity_name;
        }
    }

    /**
     * Registers Entity
     *
     * Depreicated see registerEntity
     *
     * @depricated
     */
    function _addEntity($entity_name) {

        return $this->registerEntity($entity_name);
    }


    function getEntities() {

        return $this->entities;
    }

    /**
     * Installation method
     *
     * Creates database tables and sets schema version
     *
     */
    function install() {

        $this->e->notice('Starting installation of module: '.$this->name);

        $errors = '';

        // Install schema
        if (!empty($this->entities)) {

            foreach ($this->entities as $k => $v) {

                $entity = owa_coreAPI::entityFactory($this->name.'.'.$v);
                //$this->e->debug("about to  execute createtable");
                $status = $entity->createTable();

                if ($status != true) {
                    $this->e->notice("Entity Installation Failed.");
                    $errors = true;
                    //return false;
                }

            }
        }

        // activate module and persist configuration changes
        if ($errors != true) {

            // run post install hook
            $ret = $this->postInstall();

            if ($ret == true):
                $this->e->notice("Post install proceadure was a success.");;
            else:
                $this->e->notice("Post install proceadure failed.");
            endif;

            // save schema version to configuration
            $this->c->persistSetting($this->name, 'schema_version', $this->getRequiredSchemaVersion());
            //activate the module and save the configuration
            $this->activate();
            $this->e->notice("Installation complete.");
            return true;

        } else {
            $this->e->notice("Installation failed.");
            return false;
        }

    }

    /**
     * Post installation hook
     *
     */
    function postInstall() {

        return true;
    }

    function isCliUpdateModeRequired() {

        return $this->update_from_cli_required;
    }

    /**
     * Checks for and applies schema upgrades for the module
     *
     */
    function update() {

        // list files in a directory
        $files = owa_lib::listDir(OWA_DIR.'modules'.'/'.$this->name.'/'.'updates', false);
        //print_r($files);

        $current_schema_version = $this->c->get($this->name, 'schema_version');

        // extract sequence
        foreach ($files as $k => $v) {
            // the use of %d casts the sequence number as an int which is critical for maintaining the
            // order of the keys in the array that we are going ot create that holds the update objs
            //$n = sscanf($v['name'], '%d_%s', $seq, $classname);
            $seq = substr($v['name'], 0, -4);

            settype($seq, "integer");

            if ($seq > $current_schema_version) {

                if ($seq <= $this->required_schema_version) {
                    $this->updates[$seq] = owa_coreAPI::updateFactory($this->name, substr($v['name'], 0, -4));
                    // if the cli update mode is required and we are not running via cli then return an error.
                    owa_coreAPI::debug('cli update mode required: '.$this->updates[$seq]->isCliModeRequired());
                    if ($this->updates[$seq]->isCliModeRequired() === true && !defined('OWA_CLI')) {
                        //set flag in module
                        $this->update_from_cli_required = true;
                        owa_coreAPI::notice("Aborting update $seq. This update must be applied using the command line interface.");
                        return false;
                    }
                    // set schema version from sequence number in file name. This ensures that only one update
                    // class can ever be in use for a particular schema version
                    $this->updates[$seq]->schema_version = $seq;
                }
            }

        }

        // sort the array
        ksort($this->updates, SORT_NUMERIC);

        //print_r(array_keys($this->updates));

        foreach ($this->updates as $k => $obj) {

            $this->e->notice(sprintf("Applying Update %d (%s)", $k, get_class($obj)));

            $ret = $obj->apply();

            if ($ret == true):
                $this->e->notice("Update Suceeded");
            else:
                $this->e->notice("Update Failed");
                return false;
            endif;
        }

        return true;
    }

    /**
     * Deactivates and removes schema for the module
     *
     */
    function uninstall() {

        return;
    }

    /**
     * Places the Module into the active module list in the global configuration
     *
     */
    function activate() {

        //if ($this->name != 'base'):

            $this->c->persistSetting($this->name, 'is_active', true);
            $this->c->save();
            $this->e->notice("Module $this->name activated");

        //endif;

        return;
    }

    /**
     * Deactivates the module by removing it from
     * the active module list in the global configuration
     *
     */
    function deactivate() {

        if ($this->name != 'base'):

            $this->c->persistSetting($this->name, 'is_active', false);
            $this->c->save();

        endif;

        return;
    }

    /**
     * Checks to se if the schema is up to date
     *
     */
    function isSchemaCurrent() {

        $current_schema = $this->getSchemaVersion();
        $required_schema = $this->getRequiredSchemaVersion();

        owa_coreAPI::debug("$this->name Schema version is $current_schema");
        owa_coreAPI::debug("$this->name Required Schema version is $required_schema");

        if ($current_schema >= $required_schema) {
            return true;
        } else {
            return false;
        }
    }

    function getSchemaVersion() {

        $current_schema = owa_coreAPI::getSetting($this->name, 'schema_version');

        if (empty($current_schema)) {
            $current_schema = 1;

            // if this is the base module then we need to let filters know to install the base schema
            if ($this->name === 'base') {
            //    $s = owa_coreAPI::serviceSingleton();
            //    $s->setInstallRequired();
            }
        }

        return $current_schema;
    }

    function getRequiredSchemaVersion() {

        return $this->required_schema_version;
    }

    /**
     * Registers updates
     *
     */
    function _registerUpdates() {

        return;

    }

    /**
     * Adds an update class into the update array.
     * This should be used to within the _registerUpdates method or else
     * it will not get called.
     *
     */
    function _addUpdate($sequence, $class) {

        $this->updates[$sequence] = $class;

        return true;
    }

    /**
     * Adds an event processor class to the processor array. This is used to determin
     * which class to use to process a particular event
     */
    function addEventProcessor($event_type, $processor) {
        $this->event_processors[$event_type] = $processor;
        return;
    }

    function registerMetric($metric_name, $classes, $params = array(), $label = '', $description = '', $group = '') {

        if ( ! $label ) {
            $label = $metric_name;
        }

        if ( ! $description ) {
            $description = 'No description available.';
        }

        if ( ! is_array( $classes ) ) {

            $classes = array($classes);
        }

        foreach ($classes as $class_name) {

            $map = array('name' => $metric_name, 'class' => $class_name, 'params' => $params, 'label' => $label, 'description' => $description, 'group' => $group);
            $this->metrics[$metric_name][] = $map;
        }
    }

    /**
     * Registers a metric definition which is used by the
     * resultSetExplorer and getResultSet API methods
     *
     * This method dynamically creates an owa_metric class and
     * properly configures it based on the properties passed in.
     *
     * Map properties include:
     *
     *         'name'            => '',             // the name of the metric as called via the API
     *        'label'            => '',             // the label that will be displayed in result sets
     *        'description'    => '',             // the descript displayed in the GUI
     *        'group'            => 'unknown',    // the group that this metric will belong to in the UI
     *        'entity'        => '',          // the entity to use when calculating this metric
     *                                        // you must register the same metric for each entity that
     *                                        // it can be calculated on.
     *        'metric_type'    => '',          // 'count', 'distinct_count', 'sum', or 'calculated'
     *        'data_type'        => '',          // 'integrer', 'currency', 'average'
     *        'column'        => '',          // the column of the entity to use when calculating
     *        'child_metrics'    => array(),     // if it's a clculated metric, the child metrics used in the formula.
     *        'formula'        => ''           // if it's a calculated metric, the formula to use (e.g. pageViews / visits).
     *
     *
     */
    function registerMetricDefinition( $params ) {

        $map = array(
            'name'            => '',
            'label'            => '',
            'description'    => '',
            'group'            => 'unknown',
            'entity'        => '',
            'metric_type'    => '',
            'data_type'        => '',
            'column'        => '',
            'child_metrics'    => array(),
            'formula'        => ''
        );

        $map = array_intersect_key( array_merge( $map, $params ), $map );

        if ( ! isset( $map['name'] ) ) {
            // throw exception
        }

        if ( ! isset( $map['label'] ) ) {
            $map['label'] = $map['name'];
        }

        if ( ! isset( $map['entity'] ) ) {
            // throw exception
        }

        if ( ! isset( $map['metric_type'] ) ) {
            // throw exception
        }

        if ( ! isset( $map['data_type'] ) ) {
            // throw exception
        }

        if ( isset( $map['metric_type'] )
             && $map['metric_type'] != 'calculated'
             && ! isset( $map['column'] ) )
        {

            // throw exception

        }

        if ( isset( $map['metric_type'] )
             && $map['metric_type'] === 'calculated'
             && ! isset( $map['child_metrics'] ) )
        {

            // throw exception

        }

        if ( isset( $map['metric_type'] )
             && $map['metric_type'] === 'calculated'
             && ! isset( $map['formula'] ) )
        {

            // throw exception

        }

        $definition = array(
            'name'             => $map['name'],
            'class'         => 'base.configurableMetric',
            'params'         => $map,
            'label'         => $map['label'],
            'description'     => $map['description'],
            'group'         => $map['group']
        );
        //print_r($definition);
        $this->metrics[ $map['name'] ][] = $definition;

    }

    /**
     * Register a dimension
     *
     * registers a dimension for use by metrics in producing results sets.
     *
     * @param    $dim_name string
     * @param    $entity_names    string||array the names of entity housing the dimension. uses module.name format
     * @param    $column    string the name of the column that represents the dimension
     * @param     $family    string the name of the group or family that this dimension belongs to. optional.
     * @param    $description    string    a short description of this metric, used in various interfaces.
     * @param    $label    string the lable of the dimension
     * @param     $foreign_key_name the name of the foreign key column that should
     *          be used to relate the metric entity to the dimension's entity.
     *          If one is not specfied, metrics will use any valid foreign key column they can find.
     *          Specifying this is important when the same column in a table is used by
     *          two different dimensions but the meaning of the column differs based on the value of the foreign key.
     *          a good example is the page_title column in the documents table. It is used by three dimensions:
     *          pageTitle, entryPageTitle, and existPageTitle.
     * @param    $denormalized    boolean    flag marks the dimension as being denormalized into a fact table
     *          as opposed to being housed in a related table.
     */
    function registerDimension(
            $dim_name, $entity_names, $column, $label = '', $family,
            $description = '', $foreign_key_name = '',
            $denormalized = false, $data_type = 'string') {

        if ( ! is_array( $entity_names ) ) {
            $entity_names = array($entity_names);
        }

        foreach ($entity_names as $entity) {

            $dim = array(
                'family'             => $family,
                'name'                 => $dim_name,
                'entity'             => $entity,
                'column'             => $column,
                'label'             => $label,
                'description'         => $description,
                'foreign_key_name'     => $foreign_key_name,
                'data_type'         => $data_type,
                'denormalized'         => $denormalized
            );

            if ($denormalized) {
                $this->denormalizedDimensions[$dim_name][$entity] = $dim;
            } else {
                $this->dimensions[$dim_name] = $dim;
            }
        }
    }

    function registerActions() {

        return false;
    }


    /**
     * Registers a Web Action and ontroller Implementation
     *
     * @param    $action_name    string    the name of the action used as the value in the 'do' url param
     * @param    $class_name     string    the name of the controller class
     * @param    $file            string    the path to the file housing the class
     *
     */
    protected function registerAction( $action_name, $class_name, $file ) {
		
		$s = owa_coreAPI::serviceSingleton();
    	$s->setMapValue( 'actions', $action_name, ['class_name' => $class_name, 'file' => OWA_BASE_MODULE_DIR . $file ] );

    }
    
    /**
     * Registers a REST API Action and Controller Implementation
     *
     * Routes are unique to the version/action/request_method combination
     *
     * @param	 $version		 string	   the version namespace of the route
     * @param    $route__name    string    the name of the action used as the value in the 'do' param of the request
     * @param	 $request_method string	   the HTTP request method.
     * @param    $class_name     string    the class name of the controller
     * @param    $file           string    the module path to the file housing the class
     *
     */
    function registerRestApiRoute( $version, $route_name, $request_method, $class_name, $file, $params = [] ) {
		
		if ( $file ) {
		
			$file = $this->path . $file;				
		}
		
		$s = owa_coreAPI::serviceSingleton();
		
		$s->setRestApiRoute( $this->name, $version, $route_name, $request_method, array( 'class_name' => $class_name, 'file' => $file, 'conf' => $params ) );
    }

    function registerCliCommand($command, $class) {

        $this->cli_commands[$command] = $class;
    }

    function registerFormatter($type, $formatter) {

        $this->formatters[$type] = $formatter;
    }

    function registerApiMethod($api_method_name, $user_function, $argument_names, $file = '', $required_capability = '') {

        $map = array('callback' => $user_function, 'args' => $argument_names, 'file' => $file);

        if ($required_capability) {
            $map['required_capability'] = $required_capability;
        }

        $this->api_methods[$api_method_name] = $map;
    }

    /**
     * Registers a Component Implementation
     *
     * Allows a module to register a specific implementation of a module component. This method stores
     * the mapping of where an implementation of a specific type and key is located withing the module. 
     * This is used to store maps for things like controllers, event queues, etc. 
     *
     * Implemntations can be overridden by other modules if they share the same key so consider using 
     * modules namespacing for the key (i.e. module_name.key) to avoid conflicts.
     *
     * @param    $type	string	the type/category of implementation		actions|event_queues
     * @param    $key  string	the key name of the specific implmentation
     * @param    $class_name     string    the name of the class
     * @param    $file           string    the partial path to the file housing the class withing the module dir
     *
     */
    function registerImplementation($type, $key, $class_name, $file) {

        $s = owa_coreAPI::serviceSingleton();
        $file = $this->path . $file;
        $class_info = array($class_name, $file);
        $s->setMapValue($type, $key, $class_info);
    }

    function registerBackgroundJob($name, $command, $cron_tab, $max_processes = 1) {

        $job = array('name'                =>    $name,
                     'cron_tab'            =>    $cron_tab,
                     'command'            =>    $command,
                     'max_processes'    =>    $max_processes);

        $s = owa_coreAPI::serviceSingleton();
        $s->setMapValue('background_jobs', $name, $job);
    }

    /**
     * Register Environmental Tracking Properties
     *
     * These are tracking properties that are derived from the Server environment
     * and should be added to all tracking tracking events as they are recieved.
     *
     *
     * @var $type            string    the type of tracking property environmental|regular|derived
     *
     *         environmental = properties that are only dependant on the PHP SERVER environment.
     *        regular       = properties that are set by clients
     *        derived          = properties that are derived from or dependant on other properties
     *
     * @var    $properties     array     an associative array of tracking properties
     *
     * Example:
     *
     *         'REMOTE_HOST'        => array(
     *            'default_value'        => array( 'owa_trackingEventHelpers::remoteHostDefault' ),
     *            'required'            => true,
     *            'data_type'            => 'string',
     *            'filter'            => true
     *        )
     *
     *
     * The key of the array is the name the property
     */

    function registerTrackingProperties( $type, $properties = array() ) {

        switch( strtolower( $type ) ) {

            case 'environmental':
                $map_key = 'tracking_properties_environmental';
                break;

            case 'regular':
                $map_key = 'tracking_properties_regular';
                break;

            case 'derived':
                $map_key = 'tracking_properties_derived';
                break;

            default:
                $map_key = '';
        }

        if ( is_array( $properties ) && $map_key ) {

            $s = owa_coreAPI::serviceSingleton();

            foreach ( $properties as $k => $property ) {

                $s->setMapValue( $map_key, $k, $property);
            }
        }
    }

    /**
     * Abstract method for registering individual API methods
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerApiMethods() {

        return false;
    }

    /**
     * Abstract method for registering individual CLI commands
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerCliCommands() {

        return false;
    }

    /**
     * Abstract method for registering individual Metrics
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerMetrics() {

        return false;
    }

    /**
     * Abstract method for registering individual CLI commands
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerDimensions() {

        return false;
    }

    /**
     * Abstract method for registering individual Filter Methods
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerFilters() {

        return false;
    }

    /**
     * Abstract method for registering individual Filter Methods
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerBackgroundJobs() {

        return false;
    }

    /**
     * Abstract method for registering package files to build
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     */
    function registerBuildPackages() {

        return false;
    }

    /**
     * Registers a new package of files to be built by
     * the 'build' CLI command.
     *
     * $package array    the package array takes the form of
     *
     *         'name'            => 'mypackage'
     *        'output_dir'    => '/path/to/output'
     *        'files'            => array('foo' => array('path' => '/path/to/file/file.js',
     *                                              'compression' => 'minify'))
     */
    protected function registerBuildPackage( $package ) {

        if (! isset( $package['name'] ) ) {

            throw exception('Build Package does not have a name.');
        }

        if (! isset( $package['output_dir'] ) ) {

            throw exception('Build Package does not have an output directory.');
        } else {
            //check for trailing slash
            $check = substr($package['output_dir'], -1, 1);
            if ($check != '/') {
                $package['output_dir'] = $package['output_dir'].'/';
            }
        }

        if (! isset( $package['files'] ) ) {

            throw exception('Build Package does not any files.');
        }

        // filter the pcakge in case other modules want to change something.
        $eq = owa_coreAPI::getEventDispatch();
        $package = $eq->filter( 'register_build_package', $package );

        $s = owa_coreAPI::serviceSingleton();
        $s->setMapValue('build_packages', $package['name'], $package);
    }


    /**
     * Retuns internal struct array used for saving link infos
     * @param string $ref
     * @param string $anchortext
     * @param integer $order
     * @param string $priviledge
     * @return array
     */
    private function getLinkStruct($ref,$anchortext,$order,$priviledge, $icon_class = '') {
        return array('ref' => $ref,
                    'anchortext' => $anchortext,
                    'order' => $order,
                    'priviledge' => $priviledge,
                    'icon_class' => $icon_class);
    }

    protected function registerEventQueue( $name, $map ) {

        $map['queue_name'] = $name;
        $s = owa_coreAPI::serviceSingleton();
        $s->setMapValue( 'event_queues', $name, $map );
    }

}

?>