<?php
namespace OWA\Core;

/**
 * Abstract Module Class
 * 
 *
 * NavLink: one report nav link, as getLinkStruct() builds it. NavItem: a nav
 * subgroup, a NavLink with its links under `subgroup`; only `subgroup` when a
 * link was added before the subgroup was.
 *
 * @phpstan-type NavLink array{ref: string|array<string, string>, anchortext: string, order: int|null, priviledge: string, icon_class: string}
 * @phpstan-type NavItem array{ref?: string|array<string, string>, anchortext?: string, order?: int|null, priviledge?: string, icon_class?: string, subgroup?: list<NavLink>}
 * @phpstan-type ActivationDeclaration array{activated_by: list<mixed>, intake_queue_type: string}
 * @phpstan-type JobRegistration array{name: string, command: string, schedule: string, params: array<mixed>, description: string, module: string, source: string, while_updates_pending?: bool, retry?: array<string, mixed>|null}
 */

// Modules set properties they do not declare; this attribute is PHP 8.2's
// supported way to allow it.
#[\AllowDynamicProperties]
abstract class Module {

    /**
     * Name of module: static::NAME, set by the constructor; '' until then.
     *
     * @var string
     */
    public $name = '';

    /**
     * The module's runtime name. Every module class declares its own:
     *
     *     const NAME = 'acme';
     *
     * This empty one stands for none: a module class that does not declare
     * its own is refused when it is loaded (declaresName(),
     * CoreAPI::moduleClassFactory(), CoreAPI::getPresentModules()). Read
     * without building the module by Core\VersionInfo and
     * Classes\TableInventory.
     *
     * @var string
     */
    const NAME = '';

    /**
     * The entities this module implements, by name. Read without building the
     * module (Classes\TableInventory), and registered by _registerEntities().
     *
     * @var list<string>
     */
    const ENTITIES = [];

    /**
     * Description of Module
     *
     * @var string
     */
    public $description;

    /**
     * Version of Module
     *
     * @var string
     */
    public $version;

    /**
     * Name of author of module
     *
     * @var string
     */
    public $author;

    /**
     * name used in display situations
     *
     * @var mixed
     */
    public $display_name;

    /**
     * What a report nav item shows when its module names no icon.
     * Present in the bundled font-awesome, and in the same v4-prefix family the
     * eight hand-picked icons use.
     */
    const DEFAULT_NAV_ICON = 'fa fa-chart-bar';

    /**
     * The entity every metric is computed from and every dimension is a
     * column of: the cube. A registration on any other is refused.
     */
    const FACT_ENTITY = 'base.event';

    /**
     * The settings panels this module registers, keyed by the page's action
     * (registerSettingsPanel()); null until it registers one.
     *
     * @var array<int|string, array<string, mixed>>|null
     */
    public $admin_panels;

    /**
     * Array of navigation links that this module implements: group =>
     * subgroup => item; null until it adds one.
     *
     * @var array<string, array<string, NavItem>>|null
     */
    public $nav_links;

    /**
     * Whether registerReports() has run for this module this request.
     *
     * Mirrors how nav guards itself with `empty( $v->nav_links )`. Kept as its
     * own flag rather than inferred from the registry being non-empty, because
     * a module that legitimately registers no reports would otherwise be asked
     * again on every lookup.
     *
     * @var bool
     */
    public $reports_registered = false;

    /**
     * Array of metric names that this module implements
     *
     * @var mixed
     */
    public $metrics;

    /**
     * The Module Group that the module belongs to.
     *
     * This is used often to group a module's features or functions together in the UI
     *
     * @var string
     */
    public $group;

    /**
     * The entities this module implements, by name: static::ENTITIES, and
     * any registerEntity() adds.
     *
     * @var list<string>
     */
    public $entities = [];

    /**
     * Why the last install() returned false, translated; '' when it did not.
     *
     * @var string
     */
    public $install_problem = '';

    /**
     * Why the last update() refused before applying anything, translated; ''
     * when it did not. Set when the module's schema state is unknown
     * (unknownSchemaStateProblem()).
     *
     * @var string
     */
    public $update_problem = '';

    /**
     * Required Schema Version
     *
     * @var int
     */
    public $required_schema_version;

    /**
     * Available Updates, schema version => update
     *
     * @var array<int, \OWA\Core\Update>
     */
    public $updates = [];

    /**
     * What the last update() stopped for, when an update stopped for the
     * operator rather than failing (Update::$awaiting); '' otherwise.
     *
     * @var string
     */
    public $update_awaiting = '';

    /**
     * Called by update() after each update it applies. Returning false stops
     * update() before the next one, and update() returns false. cmd=core-update-schema sets
     * it to renew its lease.
     *
     * @var callable|null
     */
    public $after_each_update = null;

    /**
     * Event Processors Map, event type => processor action
     *
     * @var array<string, string>
     */
    public $event_processors = [];

    /**
     * Dimensions, name => entity => registration
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public $denormalizedDimensions = [];

    /**
     * cli_commands, command => action
     *
     * @var array<string, string>
     */
    public $cli_commands = [];

    /**
     * Scheduled jobs this module contributes, keyed by job name.
     *
     * @var array<string, JobRegistration>
     */
    public $scheduled_jobs = [];

    /**
     * An extension's registrations that write to the service, waiting until
     * the service adds the module (Service::addModule()): a refused
     * extension's never reach it (Core\Extensions). Each is
     * ['route', version, route, method, value], ['map', map, key, value].
     *
     * @var list<array<int, mixed>>
     */
    public $pending_registrations = [];

    /**
     * Update from CLI Required flag
     *
     * Used by controllers to see if an update error was becuase it needs
     * to be applied from the command line instead of via the browser.
     * Null until update() stops for it.
     *
     * @var bool|null
     */
    public $update_from_cli_required;
    
    /**
     * Filesystem path of the module's directory
     *
     * @var string
     */
    public $path;

    /**
     * Shown on the Modules screen; nothing else reads it.
     *
     * @var bool|null
     */
    public $config_required;

    /**
     * Constructor
     *
     *
     */
    public function __construct() {

        $this->name = static::NAME;

        // runtime name stays lowercase; on-disk dir is PascalCase (PSR-4)
        $this->path = \OWA\Core\Env::moduleDir( $this->name );
        
        /**
         * Initial registration calls
         */
        $this->init();
        
        /**
         * Register Metrics
         */
        $this->registerMetricsFromConfig();

        /**
         * Register Dimensions
         */
        $this->registerDimensions();
        $this->registerDimensionsFromConfig();

        /**
         * Register CLI Commands
         */
        $this->registerCliCommands();

        /**
         * Register Scheduled Jobs -- after the commands they name
         */
        $this->registerJobs();

        /**
         * Register API Methods
         */
        $this->registerApiMethods();

        $this->_registerEventHandlers();
        $this->_registerEventProcessors();
        $this->_registerEntities();
        $this->registerActions();

    }
    
    /**
     * Initial registration hook, called first by the constructor.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function init() {
        
        return false;
    }

    /**
     * Method for registering event processors
     *
     * @return mixed unread: the constructor ignores it
     */
    public function _registerEventProcessors() {

        return false;
    }

    /**
     * Returns array of admin Links for this module to be used in navigation
     *
     * @access public
     * @return array<int|string, array<string, mixed>>|null null when it registers none
     */
    public function getAdminPanels() {

        return $this->admin_panels;
    }

    /**
     * Returns array of report links for this module that will be
     * used in report navigation
     *
     * @access public
     * @return array<string, array<string, NavItem>>|null null when it adds none
     */
    public function getNavigationLinks() {

        return $this->nav_links;
    }

    /**
     * Abstract method for registering event handlers
     *
     * Must be defined by a concrete module class for any event handlers to be registered
     *
     * @access public
     * @return void
     */
    public function _registerEventHandlers() {

        return;
    }

    /**
     * Attaches an event handler to the event queue
     *
     * @param string|list<string> $event_name
     * @param string|object $handler_name a handler class of this module, by
     *                                    file name in $dir, or the handler
     * @param string $method
     * @param string $dir
     * @return bool
     */
    public function registerEventHandler($event_name, $handler_name, $method = 'notify', $dir = 'handlers') {

        /*
         * Once per module, event and handler. A module attaches its handlers
         * when it is constructed, and it can be constructed again -- to
         * activate it, to read what it registers -- which would otherwise
         * handle each event twice for the rest of the process.
         * The handler is a new object each time, so it is known by its class.
         */
        $key = $this->name . '|' . ( is_object( $handler_name ) ? get_class( $handler_name ) : $dir . '/' . $handler_name ) . '::' . $method;

        if (!is_object($handler_name)) {

            $handler_name = \OWA\Core\CoreAPI::moduleGenericFactory($this->name, $dir, $handler_name);
        }

        $eq = \OWA\Core\CoreAPI::getEventDispatch();
        $eq->attach($event_name, [$handler_name, $method], $key);

        return true;
    }

    /**
     * Hooks a function to a filter
     *
     * @param string $filter_name
     * @param string|object $handler_name
     * @param string $method
     * @param int $priority
     * @param string $dir
     * @return void
     */
    public function registerFilter($filter_name, $handler_name, $method = '', $priority = 10, $dir = 'filters') {

        // if it's an object
        if ( is_object( $handler_name ) ) {

            \OWA\Core\CoreAPI::registerFilter($filter_name, [$handler_name, $method], $priority);

        // if it's a static method name
        } elseif ( strpos( $handler_name, '::') ) {

            \OWA\Core\CoreAPI::registerFilter($filter_name, $handler_name, $priority);

        // else try to create the class object
        } else {
            // A class name, or a class of this module named by convention.
            if ( class_exists( $handler_name ) ) {

                $class = new $handler_name();

            } else {

                $class = \OWA\Core\CoreAPI::moduleGenericFactory($this->name, $dir, $handler_name);
            }

            // register
            \OWA\Core\CoreAPI::registerFilter($filter_name, [$class, $method], $priority);
        }
    }

    /**
     * Declare one of this module's settings.
     *
     * The module is NOT a parameter: it is $this->name. A module method that
     * made its caller repeat which module it belonged to would be one more
     * thing that can disagree with itself.
     *
     * See Settings::registerField() for what the args mean. The short version:
     * `default` always; `storable` if a value can be persisted at all, without
     * which it is a static code constant and never queried; `autoload` if boot
     * needs it; `scopes` for which levels may hold a row; `type`, `options`,
     * `label` and `description` if a fieldset will render it.
     *
     * @param string $key
     * @param array<string, mixed> $args name => value, any type: `default` is
     *                                   the setting's own
     * @return void
     */
    public function registerSettingsField( $key, array $args = [] ) {

        \OWA\Core\CoreAPI::configSingleton()->registerField( $this->moduleName(), $key, $args );
    }

    /**
     * Declare a group of settings that render together, as one <fieldset>.
     *
     * The SET names its settings, rather than each setting naming its set.
     * Layout is a property of the layout, and declaring it here means the
     * order is just the order of the array -- no `order` integers to reconcile
     * between fields registered in different places, and no way for two fields
     * to claim the same position.
     *
     * A setting appears on a screen because a fieldset lists it. That is the
     * whole test: schema_version needs no flag keeping it off the settings
     * pages, because nothing lists it.
     *
     * Keys: id, legend, description, settings. The id is namespaced with this
     * module, so a bare 'tracking' becomes 'base.tracking' and two modules
     * cannot collide.
     *
     * @param array<string, mixed> $set id, legend, description, settings
     * @return void
     */
    public function registerSettingsFieldSet( array $set ) {

        $set['module'] = $this->moduleName();

        $id = (string) ( $set['id'] ?? '' );

        if ( $id === '' ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'A settings fieldset needs an id; ignoring one registered by %s.', $this->moduleName() ) );

            return;
        }

        if ( strpos( $id, '.' ) === false ) {

            $set['id'] = $this->moduleName() . '.' . $id;
        }

        \OWA\Core\CoreAPI::configSingleton()->registerFieldSet( $set );
    }

    /**
     * Settings every module has because the framework requires them, whatever
     * the module author writes.
     *
     * Core tacks these on rather than asking for them. They are not a module
     * author's business: `is_active` decides whether the module loads at all
     * and `schema_version` decides whether its updates run, so an author who
     * forgot to mark one eager would break their own activation in a way that
     * is very hard to trace back to a missing line in a declaration file.
     *
     * A module MAY still describe one -- the Modules screen toggles is_active
     * and wants a label for it -- but settingsRegistry() merges the chrome and
     * keeps autoload forced on.
     *
     * @return array<string, array{autoload: bool}>
     */
    public static function mechanicalSettings() {

        /*
         * DELIBERATELY NO DEFAULTS. Only eagerness.
         *
         * A registered default lands in default_config, and persistSetting()
         * drops any stored value equal to its default -- so declaring
         * `is_active => false` would let it delete a module's stored is_active
         * row, and declaring `schema_version => 0` would do the same to a
         * module sitting at version 0. Both make a module look uninstalled and
         * re-run its updates. These two keys carry database STATE, not configuration, and
         * nothing may treat them as restating a default.
         */
        return [
            'is_active'      => [ 'autoload' => true ],
            'schema_version' => [ 'autoload' => true ],
        ];
    }

    /**
     * The settings catalogue for every module present on disk, with the core
     * settings added.
     *
     * STATIC, and that is the point rather than a convenience: this has to be
     * answerable before any module object exists. Service::_loadModules()
     * calls getActiveModules(), which reads `is_active` out of the settings
     * array -- so modules cannot be instantiated until settings are loaded, and
     * a registry collected from module OBJECTS could never be available to
     * Settings::load(). A file can be read with no class loading, no module
     * list and no database.
     *
     * Each module may ship modules/<Dir>/settings.php returning
     *
     *     [ 'module'   => 'maxmind_geoip',
     *       'settings' => [ '<key>' => [ 'default' => ..., 'autoload' => true,
     *                                    ...chrome ] ] ]
     *
     * The file names its own module because core cannot work it out:
     * Lib::moduleDirName() is LOSSY -- 'maxmind_geoip' and 'maxmindGeoip' both
     * produce 'MaxmindGeoip' -- and the stored rows use the first form, so
     * inverting a directory name would be a guess that silently keys settings
     * under a module nobody reads.
     *
     * No `autoload` on an entry means that setting is lazy. No chrome keys mean
     * it is code-only and will never appear on a screen.
     *
     * A module ships one only if it has settings of its own (or activation
     * constants). Its is_active and schema_version are OWA's bookkeeping and
     * need no file: Settings::isRegistered() declares them for any module on
     * disk. A module with stored settings beyond those two and no file is
     * still loaded wholesale at boot, with a notice (Settings::undeclaredModules()).
     *
     * The file may also name the constants that activate the module, and the
     * intake queue type it provides (ModuleActivation):
     *
     *     'activated_by' => [ 'OWA_SQS_QUEUE_URL' ], 'intake_queue_type' => 'sqs'
     *
     * @return array{fields: array<string, array<mixed>>, declared: array<string, true>, activation: array<string, ActivationDeclaration>}
     */
    public static function settingsRegistry() {

        if ( self::$settings_registry !== null ) {

            return self::$settings_registry;
        }

        $fields     = [];
        $declared   = [];
        $activation = [];

        $mechanical = self::mechanicalSettings();

        foreach ( \OWA\Core\CoreAPI::getPresentModules() as $dir ) {

            $file = \OWA\Core\Env::moduleDir( $dir ) . 'settings.php';

            if ( ! file_exists( $file ) ) {

                continue;
            }

            $decl = include $file;

            $module = is_array( $decl ) ? (string) ( $decl['module'] ?? '' ) : '';

            if ( $module === '' || ! isset( $decl['settings'] ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s must return an array with a module name and a settings array; ignoring it.',
                    $file ) );

                continue;
            }

            $declared_settings = (array) $decl['settings'];

            /*
             * ADDED, not merely merged when mentioned: every module has is_active and
             * schema_version, whether or not its file lists them.
             */
            foreach ( $mechanical as $key => $args ) {

                // Chrome from the module if it offered any, eagerness from core.
                $declared_settings[ $key ] = array_merge(
                    (array) ( $declared_settings[ $key ] ?? [] ), $args );
            }

            foreach ( $declared_settings as $key => $args ) {

                $fields[ $module . '|' . $key ] = (array) $args;
            }

            $declared[ $module ] = true;

            // The constants that switch the module on (ModuleActivation).
            if ( ! empty( $decl['activated_by'] ) ) {

                $activation[ $module ] = [
                    'activated_by'      => array_values( (array) $decl['activated_by'] ),
                    'intake_queue_type' => (string) ( $decl['intake_queue_type'] ?? '' ),
                ];
            }
        }

        return self::$settings_registry = [ 'fields' => $fields, 'declared' => $declared, 'activation' => $activation ];
    }

    /** @var array{fields: array<string, array<mixed>>, declared: array<string, true>, activation: array<string, ActivationDeclaration>}|null settingsRegistry(), read once per process */
    private static $settings_registry = null;

    /**
     * Forget the settings registry, so the next settingsRegistry() reads the
     * modules' settings.php files again. CoreAPI::forgetSingletons() calls
     * this.
     *
     * @return void
     */
    public static function forgetSettingsRegistry() {

        self::$settings_registry = null;
    }

    /**
     * Abstract method for registering administration/settings page
     *
     * @access public
     * @return void
     */
    public function registerAdminPanels() {

        return;
    }

    /**
     * Register a settings screen: its nav entry AND its page title, once.
     *
     * `title` is the one name. The nav renders it, and the framework hands it
     * to the page, so a screen registered this way CANNOT disagree with itself.
     * A view that sets its own headline is then a bug rather than a second
     * opinion, which is what SettingsPageTitleTest asserts.
     *
     * Keys: do, title, capability, group, order. `priviledge` and `anchortext`
     * are also written, for registerSettingsPanel's consumers.
     *
     * @param array<string, mixed> $page
     * @return true
     */
    public function registerSettingsPage( $page ) {

        $title = (string) ( $page['title'] ?? '' );

        if ( $title === '' ) {

            \OWA\Core\CoreAPI::notice(
                'A settings page needs a title: it names the screen in the nav and '
              . 'on the page itself. Registering ' . ( $page['do'] ?? '?' ) . ' without one.' );
        }

        // The older shape, so the nav builder and every existing reader work
        // unchanged. anchortext IS the title -- that is the whole point.
        $page['anchortext'] = $title;
        $page['priviledge'] = $page['priviledge'] ?? 'admin';
        $page['group']      = $page['group'] ?? 'General';
        $page['order']      = $page['order'] ?? 1;

        /*
         * Which tier of the nav this page belongs to: Instance, Organization,
         * Property or Observation Profile.
         *
         * Defaulted from `group`, which is what existing registrations write
         * and which has always meant the install-wide menu. A module with
         * per-Property configuration can now say so instead of being filed
         * under Instance whatever it asked for.
         */
        $page['section'] = $page['section'] ?? $page['group'];

        /*
         * What the viewer must have, so a page asking for something stricter than
         * edit_settings is shown only to those who have it.
         */
        $page['capability'] = $page['capability'] ?? 'edit_settings';

        /*
         * The fieldsets this page renders, in order. Named by the page rather
         * than each fieldset naming its page, so layout is declared where the
         * layout decision is made and the order is just the order of the array.
         */
        $page['fieldsets'] = (array) ( $page['fieldsets'] ?? [] );

        // What marks this panel as carrying an authoritative title.
        $page['owa_titled'] = true;

        return $this->registerSettingsPanel( $page );
    }

    /**
     * Registers an admin panel with this module
     *
     * @param  array<string, mixed> $panel
     * @return true
     */
    public function registerSettingsPanel($panel) {

        // Keyed by the page's action, so registering it again replaces it. A
        // request asks for the panels more than once (a settings screen's nav,
        // then its title, then its fieldsets), and registerAdminPanels() runs
        // each time, because it also registers the fieldsets the pages render.
        if ( isset( $panel['do'] ) ) {

            $this->admin_panels[ (string) $panel['do'] ] = $panel;

        } else {

            $this->admin_panels[] = $panel;
        }

        return true;
    }

    /**
     * A top-level report nav item.
     *
     * $icon_class defaults to a generic chart rather than to nothing. The
     * template renders `<i class="owa_nav_icon {$icon_class}">` unconditionally,
     * so an empty class drew an empty glyph -- a module that shipped navigation
     * without naming an icon, which is every third-party module, sat in the nav
     * looking broken next to Base's eight. A generic icon is the honest default:
     * the item IS a report group, and a module with something better to say
     * still says it.
     *
     * $order places the item among the group's others, lowest first
     * (CoreAPI::sortNavigation). Omitted, it follows every item that gave one,
     * in registration order.
     *
     * Links already added to the subgroup (addNavigationLinkInSubGroup()) stay
     * in it.
     *
     * @param string $subgroupName
     * @param string|array<string, string> $ref an action name, or link params (reportRef())
     * @param string $anchortext
     * @param int|null $order
     * @param string $priviledge
     * @param string $groupName
     * @param string $icon_class
     * @return void
     */
    public function addNavigationSubGroup($subgroupName, $ref, $anchortext, $order = null, $priviledge = 'view_reports', $groupName = 'Reports', $icon_class = self::DEFAULT_NAV_ICON) {

        $item = $this->getLinkStruct($ref, $anchortext, $order,$priviledge, $icon_class);

        if ( isset( $this->nav_links[$groupName][$subgroupName]['subgroup'] ) ) {

            $item['subgroup'] = $this->nav_links[$groupName][$subgroupName]['subgroup'];
        }

        $this->nav_links[$groupName][$subgroupName] = $item;
    }

    /**
     * Adds a new Link to an existing Subgroup in the navigation
     *
     * @param string $subgroupName
     * @param string|array<string, string> $ref an action name, or link params (reportRef())
     * @param string $anchortext
     * @param int|null $order lowest first; omitted, after those that give one
     * @param string $priviledge
     * @param string $groupName
     * @return void
     */
    public function addNavigationLinkInSubGroup($subgroupName, $ref, $anchortext, $order = null, $priviledge = 'view_reports', $groupName = 'Reports') {
        $this->nav_links[$groupName][$subgroupName]['subgroup'][] = $this->getLinkStruct($ref, $anchortext, $order,$priviledge);
    }

    /**
     * The module's runtime name, before the constructor has set $this->name
     * too: a module declares its settings in its own constructor, before it
     * calls parent::__construct().
     *
     * @return string
     */
    protected function moduleName() {

        return $this->name !== '' ? $this->name : static::NAME;
    }

    /**
     * Registers the module's ENTITIES. Final: ENTITIES is how a module
     * declares its tables, and the only list Classes\TableInventory reads.
     *
     * @return void
     */
    final public function _registerEntities() {

        $this->registerEntity( static::ENTITIES );
    }

    /**
     * Whether $class declares its own NAME: a non-empty string constant of
     * the class itself, not one it inherits from Core\Module or another
     * module class. Boot-free, so Core\VersionInfo can ask.
     *
     * @param  string $class
     * @return bool
     */
    public static function declaresName( $class ) {

        $class = ltrim( (string) $class, '\\' );

        if ( ! class_exists( $class ) || ! defined( $class . '::NAME' ) ) {

            return false;
        }

        $constant = new \ReflectionClassConstant( $class, 'NAME' );

        return strcasecmp( $constant->getDeclaringClass()->getName(), $class ) === 0
            && is_string( $constant->getValue() ) && $constant->getValue() !== '';
    }

    /**
     * The error a module class without its own NAME is refused with.
     *
     * @param  string $class
     * @return string
     */
    public static function undeclaredNameProblem( $class ) {

        return sprintf( \OWA\Core\CoreAPI::t( '%s is not loaded as a module: it declares no NAME of its own. A module class declares its runtime name, as in const NAME = \'acme\'.' ),
            ltrim( (string) $class, '\\' ) );
    }

    /**
     * Hook for adding report nav links, called when the nav is built.
     *
     * @return mixed unread: the nav builder ignores it
     */
    public function registerNavigation() {

        return false;
    }

    /**
     * Abstract hook for registering a module's reports.
     *
     * Called LAZILY -- from the registry, when a report is actually needed --
     * and deliberately NOT from the constructor, which is where
     * registerDimensions(), registerJobs() and the rest are called from.
     *
     * The constructor runs on every request, including every tracker beacon
     * through log.php. Registering reports there would put a file read and a
     * json_decode on the logging path for each one, which is the opposite of the
     * direction that path has been going. registerNavigation() is the precedent
     * to follow, and for exactly the same reason.
     *
     * @return mixed unread: the registry ignores it
     */
    public function registerReports() {

        return false;
    }

    /**
     * A navigation ref pointing at a report by its id.
     *
     * Reports are reached through one action, so the action alone does not say
     * which report.
     *
     *     $this->addNavigationLinkInSubGroup(
     *         'Content', $this->reportRef( 'pages' ), 'Top Pages', 1 );
     *
     * @param string $id a registered report id
     * @return array{do: string, reportId: string} link parameters
     */
    protected function reportRef( $id ) {

        return [ 'do' => 'base.report', 'reportId' => $id ];
    }

    /**
     * Registers one report under a stable id.
     *
     * The id is the report's public identity -- it appears in every URL, every
     * nav link and every inter-report link -- so it should read like a name
     * (`pages`, `entry-pages`) rather than like a class.
     *
     * A definition is either a path to a JSON file, relative to the module:
     *
     *     $this->registerReport( 'pages', 'reports/pages.json' );
     *
     * ...or an array naming a controller, for a report that is not
     * configuration and never will be:
     *
     *     $this->registerReport( 'recordings', [ 'controller' => 'acme.reportRecordings' ] );
     *
     * A controller report states its one-sentence `description` in that array,
     * as a JSON definition states it in the file.
     *
     * That second form is what lets the registry be the single indirection for
     * every report. Conversion order stops mattering, because a config that says
     * "link to `document`" resolves whether `document` is JSON yet or not; and
     * the reports that stay applications -- the heatmap, the session player --
     * are reachable and linkable without the config format growing an escape
     * hatch to accommodate them.
     *
     * Registration records the definition ONLY. Nothing is read from disk here;
     * a JSON file is parsed when the report is rendered, so listing the registry
     * costs no parsing at all.
     *
     * @param string       $id          stable, url-safe, human-meaningful
     * @param string|array<string, string> $definition  json path relative to the module, or ['controller' => action]
     * @return bool false when another module holds the id
     */
    protected function registerReport( $id, $definition ) {

        if ( is_string( $definition ) ) {
            $definition = [ 'json' => $this->path . $definition ];
        }

        $definition['module'] = $this->name;

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $existing = $s->getMapValue( 'reports', $id );

        if ( $existing ) {

            /*
             * Refused rather than overwritten, and loudly. A silently shadowed
             * report is unreachable with nothing in the interface to explain
             * why -- and since the id is the public identity, whichever module
             * lost would also lose its URLs.
             */
            \OWA\Core\CoreAPI::notice( sprintf(
                'Report id "%s" is already registered by the %s module; %s cannot take it.',
                $id, $existing['module'], $this->name ) );

            return false;
        }

        $s->setMapValue( 'reports', $id, $definition );

        return true;
    }


    /**
     * Registers an Entity
     *
     * Can take an array of entities or just a single entity as a string.
     * Will add an enetiy to the module's entity array. Required for entity installation, etc.
     *
     * @param string|list<string> $entity_name
     * @return void
     */
    public function registerEntity($entity_name) {

        if (is_array($entity_name)) {
            $this->entities = array_merge($this->entities, $entity_name);
        } else {
            $this->entities[] = $entity_name;
        }
    }

    /**
     * @return list<string>
     */
    public function getEntities() {

        return $this->entities;
    }

    /**
     * Creates each of this module's tables that does not exist, from its
     * entity as it is defined now. A table that exists is left as it is, and
     * no schema version is recorded.
     *
     * install() runs it. So do cmd=core-install on an installed system and
     * the upgrade cycle (tests/tools/upgrade_cycle.php), before the pending
     * updates: an update gives the same result when the tables this creates
     * were there before it ran (v2-Schema-Updater).
     *
     * createTable() is CREATE TABLE IF NOT EXISTS and returns true either way,
     * so whether a table was there is asked first.
     *
     * @return array{created: list<string>, existing: list<string>, failed: list<string>} table names
     */
    public function createMissingTables() {

        $out = [ 'created' => [], 'existing' => [], 'failed' => [] ];

        if ( empty( $this->entities ) ) {

            return $out;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->entities as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $this->name . '.' . $name );
            $table  = $entity->getTableName();

            if ( is_object( $db ) && method_exists( $db, 'tableExists' ) && $db->tableExists( $table ) ) {

                $out['existing'][] = $table;

                continue;
            }

            $out[ $entity->createTable() ? 'created' : 'failed' ][] = $table;
        }

        return $out;
    }

    /**
     * Installation method
     *
     * Creates database tables and sets schema version
     *
     * @return bool false when a table was not created
     */
    public function install() {

        \OWA\Core\CoreAPI::notice('Starting installation of module: '.$this->name);

        $errors = '';
        $this->install_problem = '';

        // An extension that declares what a loaded module registers is not installed or activated.
        if ( \OWA\Core\Extensions::isExtension( $this ) ) {

            $clashes = \OWA\Core\Extensions::clashes( $this, array_diff_key( \OWA\Core\CoreAPI::serviceSingleton()->modules, [ $this->name => true ] ) );

            if ( $clashes ) {

                \OWA\Core\Extensions::refuse( \OWA\Core\Extensions::dirOf( $this ), implode( ' ', $clashes ) );
                $this->install_problem = implode( ' ', $clashes );

                return false;
            }
        }

        $problem = $this->unknownSchemaStateProblem();

        if ( $problem !== '' ) {

            \OWA\Core\CoreAPI::notice( $problem );
            $this->install_problem = $problem;

            return false;
        }

        $tables = $this->createMissingTables();

        // Whether every table this module owns had to be created, which decides what the schema version may be set to below.
        $all_tables_are_new = ! $tables['existing'];

        if ( $tables['failed'] ) {

            \OWA\Core\CoreAPI::notice("Entity Installation Failed.");
            $errors = true;
            $this->install_problem = sprintf( \OWA\Core\CoreAPI::t( 'Its table %s could not be created. The error log says why.' ),
                end( $tables['failed'] ) );
        }

        // activate module and persist configuration changes
        if ($errors != true) {

            // run post install hook
            $ret = $this->postInstall();

            if ($ret == true) {
                
                \OWA\Core\CoreAPI::notice("Post install procedure was a success.");
                
            } else {
                
                \OWA\Core\CoreAPI::notice("Post install procedure failed.");
            }

            /*
             * The schema version, written only when it is provably correct.
             *
             * Writing the REQUIRED version unconditionally would be wrong on a
             * re-run: CREATE TABLE IF NOT EXISTS leaves existing tables
             * untouched, and update() selects work with
             * `$seq > $current_schema_version`, so every update in between would
             * be skipped for good. The admin UI's "Activate" control calls
             * install(), so deactivate, upgrade, activate reaches here.
             *
             * Three cases:
             *
             *  - Already recorded: leave it. cmd=core-update-schema migrates from there.
             *  - Not recorded, and every table was just created at the current
             *    definition (which includes a module with no tables at all).
             *    The required version is then the truth, and recording it
             *    REPAIRS a module that was activated without being installed.
             *  - Not recorded, and a table was there. Before the install is
             *    complete the tables are from an earlier attempt of this
             *    install, made from the current entities, so the required
             *    version is recorded and the install finishes. After it, the
             *    state of the tables is unknown, and install() refused above,
             *    before creating anything (unknownSchemaStateProblem()).
             */
            $recorded = \OWA\Core\CoreAPI::getSetting( $this->name, 'schema_version' );

            if ( ! $recorded ) {

                /*
                 * Freshness alone decides it: for a module that registers no entities the
                 * loop above never runs and this is already true. Omitting a
                 * "requires schema 1" clause fails in the safe direction -- a version left
                 * unrecorded rather than one wrongly claimed current.
                 */
                if ( $all_tables_are_new || ! $this->installComplete() ) {

                    \OWA\Core\CoreAPI::persistSetting(
                        $this->name, 'schema_version', $this->getRequiredSchemaVersion() );

                } else {

                    \OWA\Core\CoreAPI::notice( self::unknownStateMessage( $this->name ) );
                }
            }
            //activate the module and save the configuration
            $this->activate();
            \OWA\Core\CoreAPI::notice("Installation complete.");
            return true;

        } else {
            \OWA\Core\CoreAPI::notice("Installation failed.");
            return false;
        }

    }

    /**
     * Post installation hook
     *
     * @return bool
     */
    public function postInstall() {

        return true;
    }

    /**
     * @return bool|null true when update() stopped for an update that runs from
     *                   the CLI only; null until it does
     */
    public function isCliUpdateModeRequired() {

        return $this->update_from_cli_required;
    }

    /**
     * A module's schema updates, sequence => class, in order: the ones
     * update() applies, and the list cmd=version-info publishes
     * (Core\VersionInfo). Static and boot-free, so both read the same list.
     *
     * Update files are PSR-4 class files in the module's Update directory:
     * UpdateNNN.php holds class UpdateNNN, and the sequence is the NNN
     * ((int) 'Update011' is 0, so the digits are taken explicitly). Any other
     * file there is not an update and is skipped rather than mis-sequenced.
     *
     * @param  string $name the module's name, such as base or maxmind_geoip
     * @return array<int, class-string> sequence => fully qualified class name
     */
    public static function updateClasses( $name ) {

        $dir   = \OWA\Core\Lib::moduleDirName( $name );
        $files = \OWA\Core\Lib::listDir( \OWA\Core\Env::moduleDir( $dir ) . 'Update', false ) ?: [];
        $out   = [];

        foreach ( $files as $file ) {

            $basename = substr( $file['name'], 0, -4 );

            if ( substr( $file['name'], -4 ) !== '.php' || ! preg_match( '/^Update(\d+)$/', $basename, $m ) ) {

                continue;
            }

            /** @var class-string $class */
            $class = '\\' . \OWA\Core\Env::moduleNamespace( $dir ) . '\\Update\\' . $basename;

            $out[ (int) $m[1] ] = $class;
        }

        ksort( $out, SORT_NUMERIC );

        return $out;
    }

    /**
     * Checks for and applies schema upgrades for the module
     *
     * @return bool
     */
    public function update() {

        $this->update_problem = $this->unknownSchemaStateProblem();

        if ( $this->update_problem !== '' ) {

            \OWA\Core\CoreAPI::notice( $this->update_problem );

            return false;
        }

        $current_schema_version = \OWA\Core\CoreAPI::getSetting($this->name, 'schema_version');

        foreach ( self::updateClasses( $this->name ) as $seq => $class ) {

            $classname = substr( $class, strrpos( $class, '\\' ) + 1 );

            if ($seq > $current_schema_version) {

                if ($seq <= $this->required_schema_version) {
                    $this->updates[$seq] = \OWA\Core\CoreAPI::updateFactory($this->name, $classname);
                    // if the cli update mode is required and we are not running via cli then return an error.
                    \OWA\Core\CoreAPI::debug('cli update mode required: '.$this->updates[$seq]->isCliModeRequired());
                    if ($this->updates[$seq]->isCliModeRequired() === true && !defined('OWA_CLI')) {
                        //set flag in module
                        $this->update_from_cli_required = true;
                        \OWA\Core\CoreAPI::notice("Aborting update $seq. This update must be applied using the command line interface.");
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

        foreach ($this->updates as $k => $obj) {

            \OWA\Core\CoreAPI::notice(sprintf("Applying Update %d (%s)", $k, get_class($obj)));

            $ret = $obj->apply();

            if ($ret == true) {
                \OWA\Core\CoreAPI::notice("Update Succeeded");

                if ( $this->after_each_update && ! call_user_func( $this->after_each_update ) ) {

                    return false;
                }
            } elseif ( $obj->awaiting !== '' ) {
                // Stopped for the operator, not failed: see Update::$awaiting.
                $this->update_awaiting = $obj->awaiting;
                return false;
            } else {
                \OWA\Core\CoreAPI::notice("Update Failed");
                return false;
            }
        }

        return true;
    }

    /**
     * Places the Module into the active module list in the global configuration
     *
     * @return bool false when is_active was not stored (CoreAPI::persistSetting())
     */
    public function activate() {
    
        $ret = \OWA\Core\CoreAPI::persistSetting( $this->name, 'is_active', true );
        \OWA\Core\CoreAPI::notice("Module $this->name activated");
        
        return $ret;
    }

    /**
     * Deactivates the module by removing it from
     * the active module list in the global configuration
     *
     * @return bool false while a constant in owa-config.php keeps it active
     */
    public function deactivate() {

        // A module its own constant activates stays active while it is set (ModuleActivation).
        $constant = self::activatedByConstant( $this->name );

        if ( $constant !== '' ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Module %s stays active while %s is set in owa-config.php.', $this->name, $constant ) );

            return false;
        }

        if ($this->name != 'base') {

            \OWA\Core\CoreAPI::persistSetting( $this->name, 'is_active', false );
        }

        return true;
    }

    /**
     * The constant keeping a module active, or ''.
     *
     * @param string $name the module's name
     * @return string
     */
    public static function activatedByConstant( $name ) {

        return \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( (string) $name, 'is_active' );
    }

    /**
     * Whether nothing is pending for this module: an update is due when this
     * is false. Its schema, here; Base adds the release (Module::isUpToDate()).
     *
     * @return bool
     */
    public function isUpToDate() {

        return $this->isSchemaCurrent();
    }

    /**
     * Checks to se if the schema is up to date
     *
     * @return bool
     */
    public function isSchemaCurrent() {

        $current_schema = $this->getSchemaVersion();
        $required_schema = $this->getRequiredSchemaVersion();

        if ($current_schema >= $required_schema) {
            return true;
        }

        \OWA\Core\CoreAPI::debug( sprintf( '%s schema is at %s and requires %s.',
            $this->name, $current_schema, $required_schema ) );

        return false;
    }

    /**
     * The stored schema_version, as stored. None stored reads as 1, and as 0
     * when one of the module's tables exists on a completed install: the
     * state of the tables is unknown then (hasUnknownSchemaState()), and no
     * update runs.
     *
     * @return int|string
     */
    public function getSchemaVersion() {

        $current_schema = \OWA\Core\CoreAPI::getSetting($this->name, 'schema_version');

        if (empty($current_schema)) {
            $current_schema = $this->hasUnknownSchemaState() ? 0 : 1;
        }

        return $current_schema;
    }

    /** What a table is renamed to when cmd=core-install --force moves it aside: <table>_before_<yyyymmddHHMMSS>. */
    const MOVED_ASIDE = '_before_';

    /** The longest table name MySQL and MariaDB take. */
    const TABLE_NAME_LIMIT = 64;

    /**
     * Whether, on an installation whose install is complete, no
     * schema_version is recorded for this module while one of its entity
     * tables exists. Nothing says which updates those tables have had, so
     * none is applied: install(), update() and cmd=core-update-schema refuse,
     * and cmd=core-install --force moves the tables aside (moveTablesAside())
     * and installs the module fresh.
     *
     * Before the install is complete the tables are from an earlier attempt
     * of this install, and a re-run creates the missing ones and finishes.
     *
     * @return bool
     */
    public function hasUnknownSchemaState() {

        return ! \OWA\Core\CoreAPI::getSetting( $this->name, 'schema_version' )
            && $this->installComplete() && $this->existingTables();
    }

    /**
     * Whether this installation's install is complete: owa-config.php is
     * there and install_complete is recorded, as InstallManager::isInstalled()
     * asks.
     *
     * @return bool
     */
    protected function installComplete() {

        return \OWA\Core\CoreAPI::configSingleton()->isConfigFilePresent()
            && (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'install_complete' );
    }

    /**
     * Why nothing is applied to this module, translated; '' when its schema
     * state is known.
     *
     * @return string
     */
    public function unknownSchemaStateProblem() {

        return $this->hasUnknownSchemaState() ? self::unknownStateMessage( $this->name ) : '';
    }

    /**
     * @param  string $name a module's name
     * @return string
     */
    public static function unknownStateMessage( $name ) {

        return sprintf( \OWA\Core\CoreAPI::t( 'Module %s has tables but no recorded schema version, so the state of its tables is unknown. Run php cli.php cmd=core-install --force to move them aside and install the module fresh.' ), $name );
    }

    /**
     * This module's entity tables that exist.
     *
     * @return list<string> table names
     */
    public function existingTables() {

        if ( empty( $this->entities ) ) {

            return [];
        }

        $out = [];

        // A server with no database to ask (a collector, an installer with no config yet) has none.
        try {

            $db = \OWA\Core\CoreAPI::dbSingleton();

            if ( ! is_object( $db ) || ! method_exists( $db, 'tableExists' ) ) {

                return [];
            }

            foreach ( $this->entities as $name ) {

                $table = \OWA\Core\CoreAPI::entityFactory( $this->name . '.' . $name )->getTableName();

                if ( $db->tableExists( $table ) ) {

                    $out[] = $table;
                }
            }

        } catch ( \Throwable $e ) {

            return [];
        }

        return $out;
    }

    /**
     * The name a table is moved aside to.
     *
     * @param  string $table
     * @param  string $stamp yyyymmddHHMMSS
     * @return string
     */
    public static function movedAsideName( $table, $stamp ) {

        return $table . self::MOVED_ASIDE . $stamp;
    }

    /**
     * Why this module's tables cannot be moved aside under $stamp, translated:
     * a new name over the length limit, or one a table has already; '' when
     * they can.
     *
     * @param  string $stamp yyyymmddHHMMSS
     * @return string
     */
    public function moveAsideProblem( $stamp ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->existingTables() as $table ) {

            $to = self::movedAsideName( $table, $stamp );

            if ( strlen( $to ) > self::TABLE_NAME_LIMIT ) {

                return sprintf( \OWA\Core\CoreAPI::t( '%1$s cannot be moved aside: %2$s is longer than %3$d characters. Nothing was renamed.' ),
                    $table, $to, self::TABLE_NAME_LIMIT );
            }

            if ( $db->tableExists( $to ) ) {

                return sprintf( \OWA\Core\CoreAPI::t( '%1$s cannot be moved aside: %2$s exists. Nothing was renamed.' ), $table, $to );
            }
        }

        return '';
    }

    /**
     * Rename each of this module's tables that exists to
     * <table>_before_<stamp>. Every new name is checked first, against the
     * length limit and against the tables there, and nothing is renamed when
     * one is refused. The tables are not dropped.
     *
     * @param  string $stamp yyyymmddHHMMSS
     * @return array{moved: array<string, string>, problem: string} old name => new name; problem '' when none
     */
    public function moveTablesAside( $stamp ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $tables = $this->existingTables();
        $out    = [ 'moved' => [], 'problem' => $this->moveAsideProblem( $stamp ) ];

        if ( $out['problem'] !== '' ) {

            return $out;
        }

        foreach ( $tables as $table ) {

            $to = self::movedAsideName( $table, $stamp );

            if ( $db->renameTable( $table, $to ) === false ) {

                $out['problem'] = sprintf( \OWA\Core\CoreAPI::t( 'Renaming %1$s to %2$s failed. The error log says why.' ), $table, $to );

                return $out;
            }

            $out['moved'][ $table ] = $to;
        }

        return $out;
    }

    /**
     * @return int
     */
    public function getRequiredSchemaVersion() {

        return $this->required_schema_version;
    }

    /**
     * Route tracking events of these types to a processor action.
     *
     * The type is the name the tracker sends -- page_view, a module's own -- and it
     * is registered under its dispatch name, tracking.<type>. An exact name
     * outranks the tracking.* wildcard Base holds for custom events, so a
     * module's type never reaches Base's processor.
     *
     * @param string|string[] $event_types current names
     * @param string          $processor   an action, e.g. acme.processEvent
     * @return void
     */
    public function addTrackingEventProcessor( $event_types, $processor ) {

        $names = [];

        foreach ( (array) $event_types as $type ) {

            $names[] = \OWA\Core\CoreAPI::trackingDispatchName( $type );
        }

        $this->addEventProcessor( $names, $processor );
    }

    /**
     * Adds an event processor class to the processor array. This is used to determin
     * which class to use to process a particular event
     *
     * @param string|list<string> $event_types dispatch names
     * @param string              $processor   an action
     * @return void
     */
    public function addEventProcessor($event_types, $processor) {
        
        if ( is_string( $event_types ) ) {
            
            $event_types = [ $event_types ];
        }
        
        foreach( $event_types as $event_type) {
        
            $this->event_processors[$event_type] = $processor;
        }
    }

    /**
     * Registers a metric definition which is used by the
     * resultSetExplorer and getResultSet API methods
     *
     * Stored as a base.configurableMetric definition, configured from the
     * properties passed in.
     *
     * Map properties include:
     *
     *        'name'              // the name of the metric as called via the API
     *        'label'             // the label shown in result sets; the name when empty
     *        'description'       // the description shown in the UI
     *        'group'             // the group the metric belongs to in the UI
     *        'entity'            // Module::FACT_ENTITY; a metric on any other is refused
     *        'metric_type'       // 'count', 'distinct_count', 'sum', 'boolean_true_count',
     *                            // 'avg_difference', or 'calculated', 'ratio', 'difference'
     *        'data_type'         // one of Metric::DATA_TYPES; any other is dropped, with a notice
     *        'column'            // the column of the entity an aggregate reads
     *        'subtrahend_column' // avg_difference: the column subtracted from 'column'
     *        'condition'         // the rows an aggregate counts
     *        'numerator', 'denominator'      // a calculated ratio, each a metric name
     *        'minuend', 'subtrahend'         // a calculated difference, each a metric name
     *        'precision'         // a ratio's decimal places
     *
     * @param array<string, mixed> $params the keys above => value; others are dropped
     * @return void
     */
    public function registerMetricDefinition( $params ) {

        $map = [
            'name'            => '',
            'label'            => '',
            'description'    => '',
            'group'            => 'unknown',
            'entity'        => '',
            'metric_type'    => '',
            'data_type'        => '',
            'column'        => '',
            /*
             * Used only by the avg_difference kind. It has to appear here even
             * though most definitions omit it: the array_intersect_key() below
             * filters params down to THIS map, so a key missing from it is
             * silently dropped rather than rejected -- and a metric that lost
             * its subtrahend would render a subtraction against nothing.
             */
            'subtrahend_column' => '',
            /*
             * Here for the same reason as subtrahend_column: array_intersect_key()
             * below filters params down to THIS map, so a key missing from it is
             * dropped in silence -- and a metric that lost its condition would
             * count every row while claiming to count some of them.
             */
            'condition'         => [],
            /*
             * A ratio's declaration. Same reason as subtrahend_column and
             * condition: array_intersect_key() below filters params down to
             * THIS map, so a key missing from it is dropped in silence.
             */
            'numerator'        => '',
            'denominator'      => '',
            // A difference's declaration, for the same reason.
            'minuend'          => '',
            'subtrahend'       => '',
            'precision'        => ''
        ];

        $map = array_intersect_key( array_merge( $map, $params ), $map );

        if ( (string) $map['name'] === '' || (string) $map['metric_type'] === '' ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'A metric definition is ignored: it names no %s.',
                (string) $map['name'] === '' ? 'name' : 'metric_type' ) );

            return;
        }

        if ( (string) $map['label'] === '' ) {

            $map['label'] = $map['name'];
        }

        if ( (string) $map['data_type'] !== '' && ! in_array( $map['data_type'], \OWA\Core\Metric::DATA_TYPES, true ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'The metric "%s" is shown unformatted: its data_type "%s" is not one of %s.',
                $map['name'], (string) $map['data_type'], implode( ', ', \OWA\Core\Metric::DATA_TYPES ) ) );

            $map['data_type'] = '';
        }

        // Computed from other metrics (Metric::setMetricType()), so it reads no
        // entity. Any other kind aggregates a column of one.
        $calculated = in_array( $map['metric_type'], [ 'calculated', 'ratio', 'difference' ], true );

        if ( ! $calculated && ( (string) $map['entity'] === '' || (string) $map['column'] === '' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'The metric "%s" is ignored: a %s metric names the entity and the column it reads.',
                $map['name'], $map['metric_type'] ) );

            return;
        }

        if ( $map['entity'] !== '' && $map['entity'] !== self::FACT_ENTITY ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'The metric "%s" is registered on %s and is ignored. Register metrics on %s.',
                $map['name'], $map['entity'], self::FACT_ENTITY ) );

            return;
        }

        $definition = [
            'name'             => $map['name'],
            'class'         => 'base.configurableMetric',
            'params'         => $map,
            'label'         => $map['label'],
            'description'     => $map['description'],
            'group'         => $map['group']
        ];
        $this->metrics[ $map['name'] ][] = $definition;

    }

    /**
     * Register a dimension
     *
     * registers a dimension for use by metrics in producing results sets.
     *
     * @param string $dim_name
     * @param string|list<string> $entity_names Module::FACT_ENTITY; a registration on any other is refused
     * @param string $column the name of the column that represents the dimension
     * @param string $label the lable of the dimension
     * @param string $family the name of the group or family that this dimension belongs to. optional.
     * @param string $description a short description of this metric, used in various interfaces.
     * @param string $foreign_key_name recorded with the dimension; nothing reads it
     * @param bool   $denormalized must be true: a dimension is a column of the fact table
     * @param string $data_type
     * @param array<string, mixed> $options more of the registration, name => value
     * @return void
     */
    public function registerDimension(
            $dim_name, $entity_names, $column, $label, $family,
            $description = '', $foreign_key_name = '',
            $denormalized = false, $data_type = 'string',
            array $options = []) {

        if ( ! is_array( $entity_names ) ) {
            $entity_names = [$entity_names];
        }

        foreach ($entity_names as $entity) {

            if ( $entity !== self::FACT_ENTITY || ! $denormalized ) {

                \OWA\Core\CoreAPI::notice( sprintf( $entity !== self::FACT_ENTITY
                    ? 'The dimension "%1$s" is registered on %2$s and is ignored: dimensions are columns of %3$s.'
                    : 'The dimension "%1$s" is not registered as denormalized and is ignored: dimensions are columns of %3$s.',
                    $dim_name, $entity, self::FACT_ENTITY ) );

                continue;
            }

            $this->denormalizedDimensions[$dim_name][$entity] = array_merge( $options, [
                'family'             => $family,
                'name'                 => $dim_name,
                'entity'             => $entity,
                'column'             => $column,
                'label'             => $label,
                'description'         => $description,
                'foreign_key_name'     => $foreign_key_name,
                'data_type'         => $data_type,
                'denormalized'         => $denormalized
            ] );
        }
    }

    /**
     * A module's dimension vocabulary, from `config/dimensions.php`.
     *
     * A dimension is a name, a column and what to call it -- data, not code --
     * so it belongs in a file beside the other vocabularies core reads:
     * `reports/*.json`, `config/tracking_properties.json`,
     * `<module>/settings.php`.
     *
     * Every module gets this for free, like settings: ship the file and the
     * dimensions are registered. Nothing has to be added to a module class.
     *
     * @return void
     */
    protected function registerDimensionsFromConfig() {

        $declaration = $this->readVocabulary( 'dimensions' );

        if ( ! $declaration ) {

            return;
        }

        foreach ( (array) $declaration['dimensions'] as $name => $d ) {

            /*
             * A dimension is a COLUMN or an EXPRESSION, and there are two
             * kinds of expression: several columns joined (`parts`) and one
             * component of a date (`datePart`). What they share is the only
             * thing the reporting seam needs to know -- the emitted SQL carries
             * an alias placeholder, so lookupDimension() substitutes into it
             * instead of writing the alias in front. `expression` says that
             * once, rather than the seam learning each kind's key.
             */
            $options = [];

            if ( isset( $d['parts'] ) ) {

                $options['parts']      = (array) $d['parts'];
                $options['expression'] = true;

                $column = \OWA\Module\Base\Classes\DimensionExpression::sql(
                    $options['parts'],
                    isset( $d['separator'] ) ? $d['separator'] : ' / ' );

            } elseif ( isset( $d['datePart'] ) ) {

                $options['datePart']   = (string) $d['datePart'];
                $options['expression'] = true;

                $column = \OWA\Module\Base\Classes\DimensionExpression::datePart(
                    isset( $d['column'] ) ? $d['column'] : 'yyyymmdd',
                    $options['datePart'] );

            } else {

                $column = $d['column'];
            }

            // Resolved by the reports API, left out of the pickers.
            if ( ! empty( $d['internal'] ) ) {

                $options['internal'] = true;
            }

            $this->registerDimension(
                $name,
                isset( $d['entity'] ) ? $d['entity'] : $declaration['entity'],
                $column,
                $d['label'],
                $d['family'],
                isset( $d['description'] ) ? $d['description'] : '',
                isset( $d['foreign_key_name'] ) ? $d['foreign_key_name'] : '',
                // A dimension declared false is refused by registerDimension().
                isset( $d['denormalized'] ) ? (bool) $d['denormalized'] : true,
                isset( $d['data_type'] ) ? $d['data_type'] : 'string',
                $options );
        }
    }

    /**
     * A module's metric vocabulary, from `config/metrics.php`.
     *
     * Same shape and the same reason. A definition names a column and a kind
     * the query builder already knows how to render, so it carries no SQL --
     * which is exactly what makes it a file rather than a class.
     *
     * @return void
     */
    protected function registerMetricsFromConfig() {

        $declaration = $this->readVocabulary( 'metrics' );

        if ( ! $declaration ) {

            return;
        }

        foreach ( (array) $declaration['metrics'] as $name => $m ) {

            $this->registerMetricDefinition( array_merge(
                [
                    'name'   => $name,
                    'entity' => $declaration['entity'],
                ],
                $m ) );
        }
    }

    /**
     * Read and check one of this module's vocabulary files.
     *
     * Refused rather than half-applied: a file that cannot say which entity its
     * names read from would register a vocabulary against nothing, and the
     * failure would surface as an empty report rather than as a bad file.
     *
     * @param  string $kind 'dimensions' or 'metrics'
     * @return array<mixed>|null the declaration, or null when there is no usable file
     */
    private function readVocabulary( $kind ) {

        $file = $this->path . 'config' . DIRECTORY_SEPARATOR . $kind . '.php';

        if ( ! file_exists( $file ) ) {

            return null;
        }

        $declaration = include $file;

        if ( ! is_array( $declaration )
          || empty( $declaration['entity'] )
          || ! isset( $declaration[ $kind ] ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s must return an array with an entity and a %s array; ignoring it.',
                $file, $kind ) );

            return null;
        }

        $declaration[ $kind ] = self::translatedVocabulary( (array) $declaration[ $kind ] );

        return $declaration;
    }

    /**
     * A vocabulary's entries with what a reader sees -- each one's
     * Strings::VOCABULARY_KEYS -- in the locale in use, for the pickers, the
     * reports and the REST metadata alike. Strings::extract() catalogues them
     * from the config file.
     *
     * @param  array<mixed> $entries name => metric or dimension declaration
     * @return array<mixed> $entries with those values translated
     */
    public static function translatedVocabulary( array $entries ) {

        foreach ( $entries as $name => $entry ) {

            foreach ( \OWA\Core\Strings::VOCABULARY_KEYS as $key ) {

                if ( is_array( $entry ) && isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && $entry[ $key ] !== '' ) {

                    $entries[ $name ][ $key ] = \OWA\Core\Strings::translate( $entry[ $key ] );
                }
            }
        }

        return $entries;
    }

    /**
     * Hook for registering this module's actions, called by the constructor.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerActions() {

        return false;
    }


    /**
     * Registers a Web Action and ontroller Implementation
     *
     * @param string $action_name the name of the action used as the value in the 'do' url param
     * @param string $class_name  the name of the controller class, PSR-4 autoloadable
     * @param string $file        ignored: a class file path, which modules written before
     *                            PSR-4 still pass
     * @return void
     */
    protected function registerAction( $action_name, $class_name, $file = '' ) {

        \OWA\Core\CoreAPI::serviceSingleton()->setMapValue( 'actions', $action_name, [ 'class_name' => $class_name ] );
    }
    
    /**
     * Registers a REST API Action and Controller Implementation
     *
     * Routes are unique to the version/action/request_method combination
     *
     * @param string $version        the version namespace of the route
     * @param string $route_name     the name of the action used as the value in the 'do' param of the request
     * @param string $request_method the HTTP request method.
     * @param string $class_name     the class name of the controller, PSR-4 autoloadable
     * @param array<string, mixed>|string $params the route's configuration, e.g. params_order. A module
     *                                     written before PSR-4 passes a class file path here and the
     *                                     configuration after it; the path is ignored.
     * @param array<string, mixed> $legacy_params the configuration, when $params is a path
     * @return void
     */
    public function registerRestApiRoute( $version, $route_name, $request_method, $class_name, $params = [], $legacy_params = [] ) {

        if ( is_string( $params ) ) {

            $params = $legacy_params;
        }

        $route = [ 'class_name' => $class_name, 'conf' => $params ];

        if ( \OWA\Core\Extensions::isExtension( $this ) ) {

            $this->pending_registrations[] = [ 'route', $version, $route_name, $request_method, $route ];

            return;
        }

        \OWA\Core\CoreAPI::serviceSingleton()->setRestApiRoute( $this->name, $version, $route_name, $request_method, $route );
    }

    /**
     * @param string $command the name typed after cmd=
     * @param string $class   the action it runs
     * @return void
     */
    public function registerCliCommand($command, $class) {

        $this->cli_commands[$command] = $class;
    }

    /**
     * Register a CLI command to be run on a schedule by cmd=schedule-run.
     *
     * A JOB IS A CLI COMMAND. Whatever the scheduler runs, an operator can run
     * by hand with the same name and see the same output -- a job that cannot be
     * reproduced from a terminal cannot be debugged from one either. It also
     * means every job already has a capability check and its own tests.
     *
     * All four arguments are stated every time. $command is deliberately NOT
     * defaulted to $name: a registration you can read without consulting the CLI
     * registry to work out whether the first argument is a label or a command is
     * worth two repeated words.
     *
     * $name is identity -- what the state row keys on, what schedule-status
     * prints, what `schedule-run job=<name>` takes. $command is what runs. They
     * are separate so one command can be scheduled more than once with different
     * arguments and cadences.
     *
     * A SCHEDULED JOB MUST BE CONVERGENT, NOT INCREMENTAL. The scheduler does not
     * backfill: a job missed for three days runs once, not three times. That is
     * safe for partition-rotate, which extends *to* a boundary computed from
     * today. A job that processes "yesterday's data" is incremental and will
     * quietly skip days -- make it convergent before registering it.
     *
     * @param string $name        job identity, unique across all modules
     * @param string $command     a name registered with registerCliCommand()
     * @param string $schedule    a cron expression, an @alias, or 'off'
     * @param array<mixed> $params arguments passed to the controller verbatim,
     *                             name => value
     * @param string $description what it does, in a sentence or two, for an
     *                            administrator: the System Health screen shows
     *                            it, and cmd=schedule-status format=markdown
     *                            writes it out for the wiki
     * @param bool   $while_updates_pending whether schedule-run runs it while
     *                            schema updates are pending, when it runs no
     *                            other job: only for a command that needs no
     *                            current schema (Controller::needsCurrentSchema())
     * @param array<string, mixed>|null $retry this job's retry policy
     *                            (Classes\RetryPolicy), over its command's; null
     *                            for the command's, else the recurring default.
     *                            An unusable one leaves the job unregistered.
     * @return void
     */
    public function registerJob( $name, $command, $schedule, $params, $description = '', $while_updates_pending = false, $retry = null ) {

        if ( $retry !== null && ( $problem = \OWA\Module\Base\Classes\RetryPolicy::problem( $retry ) ) !== null ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Job %s: %s; not registering it.', $name, $problem ) );

            return;
        }

        $this->scheduled_jobs[ $name ] = [
            'name'        => $name,
            'command'     => $command,
            'schedule'    => $schedule,
            'params'      => (array) $params,
            'description' => (string) $description,
            'module'      => $this->name,
            'source'      => 'code',
            'while_updates_pending' => (bool) $while_updates_pending,
        ];

        // Only when given, so a registration without one reads as before.
        if ( $retry !== null ) {

            $this->scheduled_jobs[ $name ]['retry'] = $retry;
        }
    }

    /**
     * A stable seed for spreading one job's schedule, per install and per job
     * (Cron::dailySpreadFor() and the rest).
     *
     * The fallbacks matter: an install that has not been configured yet has no
     * public_url, and seeding every one of those from the same empty string
     * would put exactly the installs most likely to share an image back on the
     * same minute. The directory path differs per install even then.
     *
     * @param  string $job the job's name
     * @return string
     */
    protected function jobSeed( $job ) {

        $seed = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );

        if ( $seed === '' ) {

            $seed = \OWA\Core\Env::root() . '/';
        }

        // The JOB NAME is part of it, or every daily job on one install lands
        // on the same minute -- which is the collision the spread exists to
        // avoid, just moved from between installs to within one.
        return $seed . '|' . $job;
    }

    /**
     * One of this module's registration lists: what `config/<name>.php` returns.
     *
     * A module opts in by calling the matching *FromConfig() method from its
     * register*() hook, so registration stays explicit: nothing is registered
     * because a file or a class exists. The file is included on each call, so
     * a t() call in it translates in the locale in use when the hook runs.
     *
     * @param  string $name the file's base name
     * @return array<mixed> the list; empty, with a notice, when the file is missing or returns no array
     */
    protected function registrationConfig( $name ) {

        $file = $this->path . 'config' . DIRECTORY_SEPARATOR . $name . '.php';

        if ( ! is_file( $file ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( '%s does not exist; registering nothing from it.', $file ) );

            return [];
        }

        // A static closure, so the file cannot reach $this.
        $config = ( static function ( $path ) {

            return include $path;
        } )( $file );

        if ( ! is_array( $config ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( '%s must return an array; registering nothing from it.', $file ) );

            return [];
        }

        return $config;
    }

    /**
     * registerAction() for each entry of `config/<file>.php`: action name => controller class.
     *
     * @param  string $file
     * @return void
     */
    protected function registerActionsFromConfig( $file = 'actions' ) {

        foreach ( $this->registrationConfig( $file ) as $action => $class ) {

            $this->registerAction( (string) $action, (string) $class );
        }
    }

    /**
     * registerCliCommand() for each entry of `config/<file>.php`: command => action name.
     *
     * @param  string $file
     * @return void
     */
    protected function registerCliCommandsFromConfig( $file = 'cli_commands' ) {

        foreach ( $this->registrationConfig( $file ) as $command => $action ) {

            $this->registerCliCommand( (string) $command, (string) $action );
        }
    }

    /**
     * registerRestApiRoute() for each route of `config/<file>.php`: version =>
     * route => HTTP method => [ 'class' => controller, 'conf' => the route's configuration ].
     *
     * @param  string $file
     * @return void
     */
    protected function registerRestApiRoutesFromConfig( $file = 'rest_routes' ) {

        foreach ( $this->registrationConfig( $file ) as $version => $routes ) {

            foreach ( (array) $routes as $route => $methods ) {

                foreach ( (array) $methods as $method => $r ) {

                    $this->registerRestApiRoute( (string) $version, (string) $route, (string) $method,
                        (string) $r['class'], (array) ( $r['conf'] ?? [] ) );
                }
            }
        }
    }

    /**
     * registerJob() for each entry of `config/<file>.php`: job name =>
     * [ 'command', 'schedule', 'params', 'description' ].
     *
     * `schedule` is a cron expression, an @alias or 'off', or one derived per
     * install and per job from jobSeed(): [ 'spread' => 'daily' ],
     * [ 'spread' => 'weekly' ], [ 'spread' => 'hourly', 'interval' => 6 ]
     * (hours) or [ 'spread' => 'minutely', 'interval' => 15 ]. A job without a
     * command, or with a spread not named here, is not registered.
     *
     * `while_updates_pending` true lets schedule-run run the job while schema
     * updates are pending, when it runs no other: its controller's
     * needsCurrentSchema() must answer false (ScheduleCliTest holds that).
     *
     * @param  string $file
     * @return void
     */
    protected function registerJobsFromConfig( $file = 'jobs' ) {

        foreach ( $this->registrationConfig( $file ) as $name => $job ) {

            $name     = (string) $name;
            $schedule = $job['schedule'] ?? null;

            if ( is_array( $schedule ) ) {

                $seed = $this->jobSeed( $name );

                switch ( $schedule['spread'] ?? null ) {

                    case 'daily':
                        $schedule = \OWA\Core\Cron::dailySpreadFor( $seed );
                        break;

                    case 'weekly':
                        $schedule = \OWA\Core\Cron::weeklySpreadFor( $seed );
                        break;

                    case 'minutely':
                        $schedule = \OWA\Core\Cron::minutelySpreadFor( $seed, (int) ( $schedule['interval'] ?? 15 ) );
                        break;

                    case 'hourly':
                        $schedule = \OWA\Core\Cron::hourlySpreadFor( $seed, (int) ( $schedule['interval'] ?? 6 ) );
                        break;

                    default:
                        $schedule = null;
                }
            }

            if ( empty( $job['command'] ) || ! is_string( $schedule ) || $schedule === '' ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'Job %s in %s needs a command and a schedule; not registering it.', $name, $file ) );

                continue;
            }

            $this->registerJob( $name, (string) $job['command'], $schedule,
                (array) ( $job['params'] ?? [] ), (string) ( $job['description'] ?? '' ),
                ! empty( $job['while_updates_pending'] ), $job['retry'] ?? null );
        }
    }

    /**
     * registerSettingsPage() for each of `config/<file>.php`'s `pages`, then
     * registerSettingsFieldSet() for each of its `fieldsets`.
     *
     * @param  string $file
     * @return void
     */
    protected function registerAdminPanelsFromConfig( $file = 'admin_panels' ) {

        $config = $this->registrationConfig( $file );

        foreach ( (array) ( $config['pages'] ?? [] ) as $page ) {

            $this->registerSettingsPage( $page );
        }

        foreach ( (array) ( $config['fieldsets'] ?? [] ) as $set ) {

            $this->registerSettingsFieldSet( $set );
        }
    }

    /**
     * registerSettingsFieldSet() for each entry of `config/<file>.php`.
     *
     * @param  string $file
     * @return void
     */
    protected function registerSettingsFieldSetsFromConfig( $file ) {

        foreach ( $this->registrationConfig( $file ) as $set ) {

            $this->registerSettingsFieldSet( $set );
        }
    }

    /**
     * Report nav from `config/<file>.php`: nav group => subgroup name => item.
     *
     * Each item is added with addNavigationSubGroup(), then each of its
     * `links` with addNavigationLinkInSubGroup(). An item or link names a
     * report id in `report` (reportRef()) or its link params in `ref`, gives
     * `anchortext`, and optionally `order` and `priviledge` (default
     * view_reports); an item optionally `icon_class` (default DEFAULT_NAV_ICON).
     *
     * @param  string $file
     * @return void
     */
    protected function registerNavigationFromConfig( $file = 'navigation' ) {

        foreach ( $this->registrationConfig( $file ) as $group => $subgroups ) {

            foreach ( (array) $subgroups as $name => $item ) {

                $this->addNavigationSubGroup( (string) $name, $this->navigationConfigRef( $item ),
                    (string) $item['anchortext'], $item['order'] ?? null,
                    $item['priviledge'] ?? 'view_reports', (string) $group,
                    $item['icon_class'] ?? self::DEFAULT_NAV_ICON );

                foreach ( (array) ( $item['links'] ?? [] ) as $link ) {

                    $this->addNavigationLinkInSubGroup( (string) $name, $this->navigationConfigRef( $link ),
                        (string) $link['anchortext'], $link['order'] ?? null,
                        $link['priviledge'] ?? 'view_reports', (string) $group );
                }
            }
        }
    }

    /**
     * A nav config entry's link: reportRef() of its `report`, else its `ref`.
     *
     * @param  array<string, mixed> $entry
     * @return string|array<string, string>
     */
    private function navigationConfigRef( array $entry ) {

        return isset( $entry['report'] ) ? $this->reportRef( (string) $entry['report'] ) : $entry['ref'];
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
     * @param string $type       the type/category of implementation: actions|event_queues
     * @param string $key        the key name of the specific implmentation
     * @param string $class_name the name of the class, PSR-4 autoloadable
     * @param array<mixed>|string $params passed to the class's constructor. A class file path here
     *                                     (with the array after it) is ignored.
     * @param array<mixed> $legacy_params the constructor's array, when $params is a path
     * @return void
     */
    public function registerImplementation( $type, $key, $class_name, $params = [], $legacy_params = [] ) {

        if ( is_string( $params ) ) {

            $params = $legacy_params;
        }

        // The second element is a class file path, kept empty so the shape stays.
        $this->setServiceMapValue( $type, $key, [ $class_name, '', $params ] );
    }

    /**
     * A value in one of the service's maps: at once for OWA's modules; for an
     * extension, once the service adds it (pending_registrations).
     *
     * @param  string $map
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    private function setServiceMapValue( $map, $key, $value ) {

        if ( \OWA\Core\Extensions::isExtension( $this ) ) {

            $this->pending_registrations[] = [ 'map', $map, $key, $value ];

            return;
        }

        \OWA\Core\CoreAPI::serviceSingleton()->setMapValue( $map, $key, $value );
    }

    /**
     * Register Environmental Tracking Properties
     *
     * These are tracking properties that are derived from the Server environment
     * and should be added to all tracking tracking events as they are recieved.
     *
     *
     * @param string $type the type of tracking property environmental|regular|derived
     *
     *         environmental = properties that are only dependant on the PHP SERVER environment.
     *        regular       = properties that are set by clients
     *        derived          = properties that are derived from or dependant on other properties
     *
     * @param array<string, array<string, mixed>> $properties an associative array of tracking properties
     *
     * Example:
     *
     *         'HTTP_USER_AGENT' => [
     *             'callbacks' => [ '\\OWA\\Module\\Base\\Classes\\TrackingEventHelpers::userAgentDefault' ],
     *             'required'  => true,
     *             'data_type' => 'string',
     *         ]
     *
     *
     * The key of the array is the name the property
     *
     * @return void
     */
    public function registerTrackingProperties( $type, $properties = [] ) {

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

            foreach ( $properties as $k => $property ) {

                $this->setServiceMapValue( $map_key, $k, $property );
            }
        }
    }

    /**
     * Abstract method for registering individual API methods
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerApiMethods() {

        return false;
    }

    /**
     * Abstract method for registering individual CLI commands
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerCliCommands() {

        return false;
    }

    /**
     * Abstract method for registering scheduled jobs.
     *
     * Called by a module's constructor; redefine in a concrete module class.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerJobs() {

        return false;
    }

    /**
     * Abstract method for registering individual CLI commands
     *
     * This method is called by a module's constructor
     * and should be redefined in a concrete module class.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerDimensions() {

        return false;
    }

    /**
     * Abstract method for registering individual Filter Methods
     *
     * Called for every loaded module by Service::initializeFramework(), and
     * redefined in a concrete module class.
     *
     * @return mixed unread: the constructor ignores it
     */
    public function registerFilters() {

        return false;
    }

    /**
     * Retuns internal struct array used for saving link infos
     *
     * @param string|array<string, string> $ref
     * @param string $anchortext
     * @param int|null $order
     * @param string $priviledge
     * @param string $icon_class
     * @return NavLink
     */
    private function getLinkStruct($ref,$anchortext,$order,$priviledge, $icon_class = '') {
        return ['ref' => $ref,
                    'anchortext' => $anchortext,
                    'order' => $order,
                    'priviledge' => $priviledge,
                    'icon_class' => $icon_class];
    }

    /**
     * @param string               $name
     * @param array<string, mixed> $map  the queue's configuration, name => value
     * @return void
     */
    protected function registerEventQueue( $name, $map ) {

        $map['queue_name'] = $name;
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->setMapValue( 'event_queues', $name, $map );
    }

}
