<?php
namespace OWA\Core;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 - 2010 Peter Adams. All rights reserved.
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
 * Abstract Update Class
 * 
 * Performs an Update for a specific module
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Update extends \OWA\Core\Base {

    /**
     * Module Name
     *
     * Name of the module that his update is invoked under. This is set by the
     * factory.
     *
     * @var string
     */
    var $module_name;

    /**
     * Schema Version Number
     *
     * Version number of the schema that will be in place after update is applied.
     *
     * This is set by the module's update method from the concrete class filename
     * when it creates the concrete version of this update class.  This ensures
     * that the schema version number is only set in one place (the file name) and
     * that only one concrete update class can ever be applied for a particular
     * schema version.
     *
     * @var integer
     */
    var $schema_version;

    var $is_cli_mode_required;

    function __construct() {

        return parent::__construct();
    }

    function isCliModeRequired() {

        return $this->is_cli_mode_required;
    }

    /**
     * Applies an update
     *
     * @return boolean
     */
    function apply($force = false) {

        // check for schema version. abort if not present or else updates will get out of sync.
        if (empty($this->schema_version)) {
            $this->e->notice(sprintf("Aborting %s Update (%s): Schema Version Number is not set.", get_class(), $this->module_name));
            return false;
        }

        $current_version = $this->c->get($this->module_name, 'schema_version');

        // check to see that you are applying an update that was successfully applied

        if ( ! $force ) {
            if ($current_version === $this->schema_version) {
                $this->e->notice(sprintf("Aborting %s Update (%s): Update has already been applied.", get_class(), $this->module_name));
                return false;
            }
        }

        // execute pre update proceadure
        $ret = $this->pre();

        if ($ret == true):

            $this->e->notice("Pre Update Proceadure Suceeded");

            // execute actual update proceadure
            $ret = $this->up($force);

            if ($ret == true):

                // execute post update proceadure
                $ret = $this->post();

                if ($ret == true):
                    $this->e->notice("Post Update Proceadure Suceeded");
                    $this->c->persistSetting($this->module_name, 'schema_version', $this->schema_version);
                    $this->c->save();
                    return true;
                else:
                    $this->e->notice("Post Update Proceadure Failed");
                    return false;
                endif;
            else:
                $this->e->notice("Update Proceadure Failed");
                return false;
            endif;
        else:
            $this->e->notice("Pre Update Proceadure Failed");
            return false;
        endif;

    }


    /**
     * Rollsback an update
     *
     * Returns true ONLY when down() ran and succeeded. Refusing an
     * out-of-sequence rollback and a failing down() both return false, matching
     * apply()'s contract in this class.
     *
     * (This previously returned true unconditionally, so callers could not tell
     * a completed rollback from one that never ran.)
     *
     * @return boolean true if the rollback was applied, false if refused or failed
     */
    function rollback() {

        $current_version = $this->c->get($this->module_name, 'schema_version');

        // check to see that you are rolling back either an update that was successfully applied or one that might have failed.
        // we dont want people applying rollbacks out of sequence.
        if ($current_version === $this->schema_version || $current_version === $this->schema_version - 1) {
            $ret = $this->down();
            if ($ret) {
                // only touch the current schema number if needed

                $prior_version = $current_version - 1;

                if ($current_version === $this->schema_version) {
                    $this->c->persistSetting($this->module_name, 'schema_version', $prior_version);
                    $this->c->save();
                    $this->e->notice("Rollback succeeded to version: $prior_version.");
                } else {
                    $this->e->notice("Rollback succeeded to version: $current_version.");
                }

                return true;

            } else {
                // down() ran and did not finish. This is the dangerous outcome:
                // the schema may be partially torn down, and schema_version is
                // deliberately NOT moved, so the recorded version can now
                // disagree with the actual schema.
                $this->e->notice(sprintf('Rollback of update %s FAILED part way through. The schema may be partially rolled back, and the recorded schema version is still %s. Inspect the schema before retrying or re-applying.', $this->schema_version, $current_version));

                return false;
            }
        }

        // Refused: nothing ran, the schema is untouched.
        $this->e->notice(sprintf('Rollback of update %s cannot be applied because it does not appear that it update %s has been applied to your instance. Your current schema version is only %s', $this->schema_version, $this->schema_version, $current_version));

        return false;
    }

    /**
     * Abstract Pre-update hook
     *
     * @return boolean
     */
    function pre() {

        return true;
    }

    /**
     * Abstract Post-update hook
     *
     * @return boolean
     */
    function post() {

        return true;
    }

    /**
     * Abstract Method for update
     *
     * @return boolean
     */
    /**
     * Add a column, treating "it is already there" as success.
     *
     * addColumn() answers FALSE both for "could not" and for "already exists",
     * so a migration that stops on the second never writes its schema version --
     * and every request afterwards refuses with "OWA Updates required" while
     * the database is in fact correct.
     *
     * THIS IS NOT A RE-RUN GUARD. It is the ordinary case for any install that
     * jumps several versions at once. A table CREATED by an earlier migration is
     * built from the CURRENT entity definition, which already carries every
     * column that LATER migrations add -- so the column exists before the
     * migration that adds it ever runs.
     *
     * Found on a live deploy: an installation at schema 20 ran Update021, which
     * created owa_property complete with the archived_date that Update023 then
     * tried to add. The upgrade stopped dead at 23, on a database that was
     * already correct.
     *
     * Interpolated, not bound: SHOW COLUMNS takes no parameters, and both values
     * come from the migration's own code rather than from a request.
     *
     * @param  object $entity a table entity
     * @param  string $column
     * @return bool   false only when the column is genuinely missing and could
     *                not be added
     */
    protected function addColumnIfMissing( $entity, $column ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $existing = (array) $db->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'",
            $entity->getTableName(),
            $column ) );

        if ( $existing ) {

            return true;
        }

        return $entity->addColumn( $column ) !== false;
    }

    /**
     * Drop a column, treating "it is already gone" as success.
     *
     * The mirror of addColumnIfMissing(), and it exists for the mirror reason:
     * a down() has to be runnable twice. MySQL has no DROP COLUMN IF EXISTS, so
     * the check is explicit -- and without it a rollback that got half way
     * through cannot be finished, which is the worst moment to be unable to
     * repeat a step.
     *
     * @param  object $entity
     * @param  string $column
     * @return bool   false only when the column is there and could not be dropped
     */
    protected function dropColumnIfPresent( $entity, $column ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $existing = (array) $db->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'",
            $entity->getTableName(),
            $column ) );

        if ( ! $existing ) {

            return true;
        }

        return $entity->dropColumn( $column ) !== false;
    }

    function up() {

        return false;
    }

    /**
     * Abstract Method for reversing an update
     *
     * @return boolean
     */
    function down() {

        return false;
    }

}

?>