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
     * cmd=install, built without its constructor's CLI-only gate and
     * OWA_INSTALLING define, and installing through the recording manager.
     */
    protected function installCli(array $params): \OWA\Module\Base\Controller\InstallCli
    {
        $im  = $this->im;
        $cli = new class($params, $im) extends \OWA\Module\Base\Controller\InstallCli {
            private $recording;

            public function __construct($params, $im)
            {
                $this->recording = $im;
                \OWA\Core\AdminController::__construct($params);
            }

            protected function installManager()
            {
                return $this->recording;
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
