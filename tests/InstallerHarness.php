<?php

/**
 * The installer's controllers against a recording InstallManager and settings
 * object. Nothing reaches the database or owa-config.php; a test reads back
 * what a step did, in order, from InstallerRecording::$calls.
 */
final class InstallerRecording
{
    /** @var list<string> */
    public static $calls = [];
}

class RecordingInstallManager extends \OWA\Module\Base\Classes\InstallManager
{
    /** @var bool whether finishInstall() commits; false: the last step failed and nothing was saved */
    public $commits = true;

    /** @var bool what checkDbConnection() answers */
    public $connects = true;

    /** @var bool what isInstallComplete() answers */
    public $installed = false;

    /** @var list<mixed> each password createAdminUser() was given */
    public $passwords = [];

    public function installSchema()
    {
        InstallerRecording::$calls[] = 'installSchema';

        return true;
    }

    public function finishInstall(callable $writes)
    {
        InstallerRecording::$calls[] = 'finishInstall:begin';
        $site_id = $writes();
        InstallerRecording::$calls[] = 'finishInstall:end';

        return $this->commits ? $site_id : false;
    }

    public function createAdminUser($user_id, $email_address, $password = '')
    {
        InstallerRecording::$calls[] = 'createAdminUser:' . $user_id;
        $this->passwords[] = $password;

        return $password;
    }

    public function createDefaultSite($domain, $name = '', $description = '', $site_family = '', $site_id = '')
    {
        InstallerRecording::$calls[] = 'createDefaultSite:' . $domain;

        return 'owatest-site';
    }

    public function announceInstallComplete($user_id, $site_id)
    {
        InstallerRecording::$calls[] = 'announceInstallComplete:' . $user_id . ':' . $site_id;
    }

    public function checkDbConnection()
    {
        return $this->connects;
    }

    public function isInstallComplete()
    {
        return $this->installed;
    }

    public function isInstalled()
    {
        return $this->installed;
    }

    /** @var string|null what adminUserId() answers */
    public $admin = 'owatest-admin';

    /** @var bool what hasSite() answers */
    public $site = true;

    /** @var list<string> what secureDataDir() says it changed */
    public $permissions = [];

    public function adminUserId()
    {
        return $this->admin;
    }

    public function hasSite()
    {
        return $this->site;
    }

    public function secureDataDir($web_group = '')
    {
        InstallerRecording::$calls[] = 'secureDataDir:' . $web_group;

        return $this->permissions;
    }

    /** @var list<string> the tables createMissingTables() says it created */
    public $created = [];

    /** @var list<string> the tables createMissingTables() says it could not create */
    public $notCreated = [];

    public function createMissingTables()
    {
        InstallerRecording::$calls[] = 'createMissingTables';

        return ['created' => $this->created, 'failed' => $this->notCreated, 'problem' => null];
    }

    /** @var list<string> what unknownStateModules() answers: the modules with tables and no recorded version */
    public $unknown = [];

    /** @var list<string> the other modules moveAsideForNewInstall() says it moved */
    public $others = [];

    /** @var string what reinstallModule() and moveAsideForNewInstall() answer as their problem */
    public $moveProblem = '';

    public function unknownStateModules()
    {
        return $this->unknown;
    }

    public function reinstallModule($name, $stamp)
    {
        InstallerRecording::$calls[] = 'reinstallModule:' . $name . ':' . $stamp;

        if ($this->moveProblem === '') {
            $this->unknown = array_values(array_diff($this->unknown, [$name]));
        }

        return ['moved' => $this->moveProblem === '' ? ['owa_' . $name . '_chunk' => 'owa_' . $name . '_chunk_before_' . $stamp] : [],
            'problem' => $this->moveProblem];
    }

    public function moveAsideForNewInstall($stamp)
    {
        InstallerRecording::$calls[] = 'moveAsideForNewInstall:' . $stamp;

        if ($this->moveProblem !== '') {
            return ['moved' => [], 'modules' => [], 'problem' => $this->moveProblem];
        }

        $this->unknown = [];

        return ['moved' => ['owa_site' => 'owa_site_before_' . $stamp], 'modules' => $this->others, 'problem' => ''];
    }
}

/** Settings whose writes are recorded rather than made; reads go to the real settings. */
class RecordingSettings extends \OWA\Module\Base\Classes\Settings
{
    /** @var string the constant configFileConstantFor() names for every setting, or '' */
    public $constant = '';

    /** @var bool what isConfigFilePresent() answers */
    public $present = true;

    public function __construct()
    {
    }

    public function persistSetting($module, $key, $value)
    {
        InstallerRecording::$calls[] = 'persistSetting:' . $module . '.' . $key . '=' . json_encode($value);

        return true;
    }

    public function set($module, $key, $value)
    {
        InstallerRecording::$calls[] = 'set:' . $module . '.' . $key . '=' . json_encode($value);
    }

    public function save()
    {
        InstallerRecording::$calls[] = 'save';

        return true;
    }

    public function get(string $module, string $key)
    {
        return \OWA\Core\CoreAPI::configSingleton()->get($module, $key);
    }

    public function configFileConstantFor($module, $key)
    {
        return $this->constant;
    }

    public function isConfigFilePresent()
    {
        return $this->present;
    }

    public function applyConfigConstants()
    {
    }
}

trait InstallerHarness
{
    /** @var RecordingInstallManager */
    protected $im;

    /** @var RecordingSettings */
    protected $settings;

    protected function setUpInstaller(): void
    {
        InstallerRecording::$calls = [];
        $this->im       = new RecordingInstallManager();
        $this->settings = new RecordingSettings();
    }

    /**
     * An installer screen or action, built as the dispatcher builds it, with
     * the recording manager and settings in place.
     *
     * @template T of \OWA\Core\Controller\Install
     * @param class-string<T> $class
     * @return T
     */
    protected function installController(string $class, array $params)
    {
        $c     = new $class($params);
        $c->im = $this->im;
        $c->c  = $this->settings;

        return $c;
    }

    /**
     * cmd=core-install, built without its constructor's CLI-only gate,
     * installing through the recording manager. $stdin is the line
     * --password-stdin reads; reading it when null fails the test. A release
     * named is recorded in $releases, never installed; what it prints is in
     * $written.
     */
    protected function coreInstallCli(array $params, ?string $stdin = null): \OWA\Module\Base\Controller\CoreInstallCli
    {
        $im  = $this->im;
        $cli = new class($params, $im, $stdin) extends \OWA\Module\Base\Controller\CoreInstallCli {
            /** @var list<string> */
            public $written = [];

            /** @var list<array<int, mixed>> */
            public $releases = [];

            /** @var string what install() and installFile() answer */
            public $answer = \OWA\Module\Base\Classes\AutoUpdate\Install::OK;

            /** @var array<string, array{outcome: string, message: string, final: bool}> a command run in the process => how it ended; ok when not here */
            public $outcomes = [];

            /** @var bool whether updates are pending before the schema updates run */
            public $pending = true;

            private $recording;

            private $stdin;

            public function __construct($params, $im, $stdin)
            {
                $this->recording = $im;
                $this->stdin     = $stdin;
                \OWA\Core\AdminController::__construct($params);
                $this->setRequiredCapability('edit_modules');
            }

            protected function installManager()
            {
                return $this->recording;
            }

            protected function readStdin()
            {
                if ($this->stdin === null) {
                    throw new \LogicException('standard input was read');
                }

                return $this->stdin;
            }

            protected function write($lines)
            {
                array_push($this->written, ...(array) $lines);
            }

            protected function install($version, $force)
            {
                $this->releases[] = ['version', $version, $force];

                return ['outcome' => $this->answer, 'message' => 'answered ' . $this->answer, 'lines' => []];
            }

            protected function runCommand($class)
            {
                $short = substr($class, strrpos($class, '\\') + 1);
                \InstallerRecording::$calls[] = 'run:' . $short;

                return $this->outcomes[$short] ?? ['outcome' => 'ok', 'message' => '', 'final' => false];
            }

            protected function updatesPending()
            {
                return $this->pending;
            }

            protected function nextUpdate()
            {
                return 'base.76';
            }

            protected function stamp()
            {
                return '20261128120000';
            }

            protected function installFile($file, $sig, $force)
            {
                $this->releases[] = ['file', $file, $sig, $force];

                return ['outcome' => $this->answer, 'message' => 'answered ' . $this->answer, 'lines' => []];
            }
        };

        $cli->c = $this->settings;

        return $cli;
    }

    /** The recorded calls whose name starts with $prefix. */
    protected function recorded(string $prefix): array
    {
        return array_values(array_filter(InstallerRecording::$calls, static function ($call) use ($prefix) {
            return strpos($call, $prefix) === 0;
        }));
    }
}
