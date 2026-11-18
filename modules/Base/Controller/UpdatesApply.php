<?php
namespace OWA\Module\Base\Controller;

/**
 * Updates Application Controller
 */

class UpdatesApply extends \OWA\Core\Controller {

    /**
     * DELIBERATELY has no required capability and no nonce, for the same reason
     * the sibling base.updates has none: the schema has to be brought forward
     * before the rest of the application can be relied on, and the authentication
     * path is part of what may be waiting on it.
     *
     * Gating it would make the documented upgrade unusable for a signed-out
     * admin: base.updates renders anonymously, so its Apply link carries a nonce
     * minted with no user_id, and createNonce() binds to user_id, so that nonce
     * can never verify once the admin signs in.
     *
     * WordPress takes the same position for the equivalent step: wp-admin/upgrade.php
     * loads wp-load.php rather than admin.php and calls wp_upgrade() with no
     * capability check and no nonce.
     *
     * What that gives up is a crafted link inducing an update. The exposure is
     * small: the work is idempotent, does nothing unless the schema is behind,
     * and produces only the change the administrator was already being told to
     * make. Weighed against an upgrade path that cannot be completed from the
     * browser, that is the better trade.
     *
     * `php cli.php cmd=update` (base.updatesApplyCli) remains the route for
     * updates that cannot run over the web; updates 005/006/007/010 require it.
     *
     * ONE AT A TIME. Anyone can request this, and a reload during a slow update
     * would apply the same updates twice at once. A lease (JobLease, in owa_job_lock, which a 1.14 schema already
     * has) lets one request apply them; another is told they are under way.
     * An update the web may run is bounded; one that is not runs from the
     * command line, so the lease outlasts any web run, and a request that died
     * holding it frees it when it expires.
     */
    const LOCK = 'updates-apply';

    const LOCK_SECONDS = 3600;

    public function action() {

        $lock = new \OWA\Module\Base\Classes\JobLease( self::LOCK );

        if ( ! $lock->acquire( self::LOCK_SECONDS ) ) {

            $this->set( 'error_msg', $this->getMsg( 3313 ) );
            $this->setView( 'base.error' );

            return;
        }

        try {

            $this->applyUpdates();

        } finally {

            $lock->release();
        }
    }

    /**
     * @return void
     */
    protected function applyUpdates() {

        // fetch list of modules that require updates
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $modules = $s->getModulesNeedingUpdates();

        // foreach do update in order

        $error = false;
        // Set alongside $error inside the loop below, so at runtime it is always
        // defined by the time it is read. Initialised anyway: the correlation is
        // not visible to a reader (or to static analysis) at the point of use.
        $cli_update_required = false;

        foreach ($modules as $k => $v) {

            $ret = $s->modules[$v]->update();

            if ($ret != true):
                $error = true;
                // if there is an error check to see if it's because the cli update mode is required
                $cli_update_required = $s->modules[$v]->isCliUpdateModeRequired();
                break;
            endif;

        }

        if ($error === true) {

            if($cli_update_required) {
                $this->set('error_msg', $this->getMsg(3311));
            } else {
                $this->set('error_msg', $this->getMsg(3307));
            }

            $this->setView('base.error');
        } else {

            // add data to container
            $this->setStatusCode( 3308 );
            $this->set('do', 'base.optionsGeneral');
            $this->setViewMethod('redirect');
        }
    }
}
