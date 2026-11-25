<?php
namespace OWA\Module\Base\Controller;

/**
 * Updates Application Controller
 */

class CoreUpdateSchemaCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Applies pending schema updates for every module, in order. When an update needs a choice from the operator, it stops and says what to run.',
            'arguments'   => [
                '--listpending'                => 'List the modules with pending updates, and apply nothing.',
                'apply=<module>.<sequence>'    => 'Apply one update.',
                'rollback=<module>.<sequence>' => 'Roll back one update.',
                '--force'                      => 'With apply=: apply out of sequence.',
                'since=<cutoff>'               => 'For the 1.x history migration: migrate events newer than a date (yyyymmdd) or a period such as 2years.',
                '--all'                        => 'For the 1.x history migration: migrate everything.',
            ],
        ];
    }
    
    /** @var \OWA\Module\Base\Classes\JobLease|null the lease this run holds */
    protected $update_lease = null;

    /** @var bool whether a renewal found the lease taken by another run */
    protected $lease_lost = false;

    public function __construct($params) {
        define('OWA_UPDATING', true);
        parent::__construct($params);
    }

    /**
     * apply=, rollback= and the bare command take base.updatesApply's lease, so
     * no two runs, from the web or from here, change the schema at once. The
     * bare command renews it between updates (renewLease()). listpending
     * changes nothing and takes none.
     */
    public function action() {

        if ($this->isParam('listpending')) {

            return $this->listPendingUpdates();
        }

        // An install runs the schema updates itself: cmd=core-update-schema by hand waits
        // for it, as every other CLI run does. Inside the install (Finish)
        // this process holds the lock.
        if ( ! \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$held_here
                && \OWA\Module\Base\Classes\AutoUpdate\InstallLock::isHeld() ) {

            return $this->fail( \OWA\Core\CoreAPI::t( 'An automatic update is being installed; it runs the schema updates itself. Try again once it has finished.' ) );
        }

        // Inside the install the lease is the install's own (Finish): what a
        // Finish killed during the updates left is taken back.
        $owner = \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$held_here
            ? \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$schema_lease : null;
        $lock  = new \OWA\Module\Base\Classes\JobLease( UpdatesApply::LOCK, $owner );

        if ( $owner !== null ) {

            $lock->release();
        }

        if ( ! $lock->acquire( UpdatesApply::LOCK_SECONDS ) ) {

            return $this->fail( sprintf(
                \OWA\Core\CoreAPI::t( 'Updates are already being applied by another run. If none is running, cmd=schedule-run --force-release job=%s frees the lock.' ),
                UpdatesApply::LOCK
            ) );
        }

        $this->update_lease = $lock;

        try {

            if ($this->getParam('apply')) {

                return $this->apply($this->get('apply'));
            }

            if ($this->getParam('rollback')) {

                return $this->rollback($this->get('rollback'));
            }

            return $this->applyPending();

        } finally {

            $lock->release();
            $this->update_lease = null;
        }
    }

    /**
     * Once a run leaves no update pending, the record of an install whose
     * schema updates did not complete (Finish) goes: the Updates screen and
     * core-update-status stop saying to run this. Inside the install Finish
     * clears it itself.
     *
     * @return void
     */
    protected function clearFinishedFailure() {

        if ( \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$held_here ) {

            return;
        }

        $downloads = new \OWA\Module\Base\Classes\AutoUpdate\Downloads();
        $failure   = $downloads->failure();

        if ( ! empty( $failure['booted'] ) && ( $failure['version'] ?? null ) === \OWA\Core\Env::version() ) {

            $downloads->clearFailure();
        }
    }

    /**
     * Renew the lease, so a run of many updates never outlives it. Called after
     * each update a module applies and between modules.
     *
     * @return bool false when another run has taken the lease over
     */
    protected function renewLease() {

        if ( ! $this->update_lease ) {

            return true;
        }

        $this->heartbeat();
        \OWA\Module\Base\Classes\AutoUpdate\InstallLock::touch();

        if ( $this->update_lease->refresh( UpdatesApply::LOCK_SECONDS ) ) {

            return true;
        }

        $this->lease_lost = true;

        return false;
    }

    /**
     * Apply every module's pending updates, in order.
     *
     * @return null action() returns it
     */
    protected function applyPending() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $modules = $s->getModulesNeedingUpdates();
        
        // foreach do update in order
        if (!empty($modules)) {
            $error = false;
            $first = true;
            $lost  = \OWA\Core\CoreAPI::t( 'Stopped before the next update: another run has taken over the updates lock. cmd=core-update-schema --listpending shows what is left.' );

            foreach ($modules as $k => $v) {

                if ( ! $first && ! $this->renewLease() ) {

                    return $this->fail( $lost );
                }

                $first  = false;
                $module = $s->modules[$v];

                // A module that is not a Core\Module may not have the hook.
                $hooked = property_exists( $module, 'after_each_update' );

                if ( $hooked ) {

                    $module->after_each_update = function () {

                        return $this->renewLease();
                    };
                }

                try {

                    $ret = $module->update();

                } finally {

                    if ( $hooked ) {

                        $module->after_each_update = null;
                    }
                }

                if ( $this->lease_lost ) {

                    return $this->fail( $lost );
                }

                if ($ret != true):
                    $awaiting = (string) $s->modules[$v]->update_awaiting;
                    $error = true;
                    break;
                endif;

            }

            if ( ( $awaiting ?? '' ) !== '' ) {
                // An update needs the operator first, and has said what. The
                // run did not complete, so it is a failure: exit code 1.
                $this->fail( sprintf(
                    'Updates paused: one needs %s. Do what it says above, then run cmd=core-update-schema again.', $awaiting ) );
            } elseif ($error === true) {
                $this->fail( $this->getMsg( 3307 )['message'] );
            } else {
                
                // add data to container
                \OWA\Core\CoreAPI::notice( $this->getMsg( 3308 )['message'] );
                $this->clearFinishedFailure();
            }
        } else {
            \OWA\Core\CoreAPI::notice("There are no modules with pending updates to apply.");
            $this->clearFinishedFailure();
        }

        return null;
    }
    
    /**
     * @return null action() returns it
     */
    public function listPendingUpdates() {
        
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $modules = $s->getModulesNeedingUpdates();
        if ($modules) {
            \OWA\Core\CoreAPI::notice(sprintf("Updates pending include: %s",print_r($modules, true)));
        } else {
            \OWA\Core\CoreAPI::notice("No updates are pending.");
        }

        return null;
    }
    
    /**
     * <module>.<sequence> as array( module, sequence ), or null.
     *
     * @param mixed $update
     * @return array{string, string}|null
     */
    public static function parseUpdateName( $update ) {

        return is_string( $update ) && preg_match( '/^([A-Za-z0-9_]+)\.(\d+)$/', $update, $m )
            ? [ $m[1], $m[2] ]
            : null;
    }

    /**
     * The update named <module>.<sequence>, or null after saying why not.
     *
     * @param mixed  $update
     * @param string $arg  apply or rollback
     * @return object|null
     */
    protected function namedUpdate( $update, $arg ) {

        $name = self::parseUpdateName( $update );

        if ( ! $name ) {

            return $this->usageError( sprintf( '%s= takes <module>.<sequence>, such as base.86.', $arg ) );
        }

        try {

            return \OWA\Core\CoreAPI::updateFactory( $name[0], $name[1] );

        } catch ( \Exception $e ) {

            return $this->usageError( $e->getMessage() );
        }
    }

    /**
     * @param  mixed $update <module>.<sequence>, as the request named it
     * @return null action() returns it
     */
    public function apply($update) {

        $u = $this->namedUpdate( $update, 'apply' );

        if ( ! $u ) {

            return null;
        }
        
        // check for force command param
        //
        // The key is 'force', not '--force': cli.php strips a leading -- and treats
        // a valueless switch as true, so --force, --force=1 and force=1 all arrive
        // here as 'force'.
        $force = false;
        if ($this->isParam('force')) {
            
            $force = true;
        }
        
        $ret = $u->apply($force);
        
        if ($ret) {
            \OWA\Core\CoreAPI::notice("Updates applied successfully.");
        } else {
            $this->fail("The update did NOT apply. See the messages above for why.");
        }

        return null;
    }
    
    /**
     * @param  mixed $update <module>.<sequence>, as the request named it
     * @return null action() returns it
     */
    public function rollback($update) {

        $u = $this->namedUpdate( $update, 'rollback' );

        if ( ! $u ) {

            return null;
        }

        // rollback() returns false when it refuses an out-of-sequence rollback
        // and when down() fails part way. Reporting "completed" regardless told
        // operators the schema had moved when nothing had happened.
        $ret = $u->rollback();

        if ($ret) {
            \OWA\Core\CoreAPI::notice("Rollback completed.");
        } else {
            $this->fail("Rollback did NOT complete. See the messages above for whether it was refused or failed part way.");
        }

        return null;
    }
    
}
