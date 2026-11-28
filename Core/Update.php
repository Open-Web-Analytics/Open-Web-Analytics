<?php
namespace OWA\Core;

/**
 * Abstract Update Class
 * 
 * Performs an Update for a specific module
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
    public $module_name;

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
    public $schema_version;

    /**
     * Whether this update may run only from the command line.
     *
     * @var bool
     */
    public $is_cli_mode_required = false;

    /**
     * Set by an update that stops for the operator rather than failing: a
     * choice to make, or something to do first. up() returns false with it
     * set, and every layer above reports it as waiting, not as a failure.
     * The update says what is needed in its own notice.
     *
     * @var string
     */
    public $awaiting = '';

    /**
     * Tables to use instead of the install's, by the key tableFor() is given.
     * TESTS ONLY.
     *
     * @var array<string,string>
     */
    public $tables = [];

    /**
     * @return bool
     */
    public function isCliModeRequired() {

        return $this->is_cli_mode_required;
    }

    /**
     * The table an update reads and writes for this key: the one $tables holds
     * for it, else the entity's table for a module.entity key, else the key
     * under the table prefix (a table no entity describes any more).
     *
     * @param  string $key 'module.entity', or a bare table name
     * @return string
     */
    public function tableFor( $key ) {

        if ( isset( $this->tables[ $key ] ) ) {

            return $this->tables[ $key ];
        }

        if ( strpos( $key, '.' ) !== false ) {

            return \OWA\Core\CoreAPI::entityFactory( $key )->getTableName();
        }

        return \OWA\Core\Entity::TABLE_PREFIX . $key;
    }

    /**
     * Applies an update
     *
     * @param  bool $force apply it whatever the recorded schema version
     * @return bool
     */
    public function apply($force = false) {

        // check for schema version. abort if not present or else updates will get out of sync.
        if (empty($this->schema_version)) {
            $this->e->notice(sprintf("Aborting %s Update (%s): Schema Version Number is not set.", static::class, $this->module_name));
            return false;
        }

        // From $this->c, the settings object the new version is persisted to.
        $current_version = $this->c->get( $this->module_name, 'schema_version' );

        /*
         * Only the next update applies: the one numbered one above the schema.
         * An update at or below the schema has been applied, and running it
         * again re-runs it over the changes of every update after it; one
         * further ahead skips the updates between. --force applies any of them.
         * No recorded version reads as 1. With the module's tables there, its
         * state is unknown and cmd=core-update-schema refuses apply= and
         * rollback= before this runs (Module::hasUnknownSchemaState()).
         */
        if ( ! $force ) {

            $current = empty( $current_version ) ? 1 : (int) $current_version;
            $seq     = (int) $this->schema_version;

            if ( $current >= $seq ) {
                $this->e->notice(sprintf("Aborting %s Update (%s): the schema is at %d, so update %d has been applied. Use --force to apply it again.",
                    static::class, $this->module_name, $current, $seq));
                return false;
            }

            if ( $current !== $seq - 1 ) {
                $this->e->notice(sprintf("Aborting %s Update (%s): the schema is at %d, so update %d is next, not %d. Use --force to apply it out of sequence.",
                    static::class, $this->module_name, $current, $current + 1, $seq));
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
            elseif ( $this->awaiting !== '' ):
                $this->e->notice( sprintf( 'Update stopped: it needs %s.', $this->awaiting ) );
                return false;
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
     * What down() cannot put back, in a sentence, for an update whose up()
     * deletes or merges data. Empty for an exact inverse. Logged when the
     * rollback starts.
     *
     * @var string
     */
    public $down_does_not_restore = '';

    /**
     * Why this update must be installed by hand, and the backup to take first,
     * in a sentence or two; empty for one that may install itself. A release
     * with such an update among those an installation would run is staged and
     * announced, never installed automatically (Step 1: release check reads it
     * from release.php). Never set in a patch release.
     *
     * For example: 'It rewrites every event's browser value. Back up the data
     * tables first: php cli.php cmd=backup scope=data'.
     *
     * Declared as a default, not set in the constructor: release.php reads it
     * without constructing the update (Core\VersionInfo).
     *
     * @var string
     */
    public $manual_install = '';

    /**
     * Rollsback an update
     *
     * Returns true ONLY when down() ran and succeeded. Refusing an
     * out-of-sequence rollback and a failing down() both return false, matching
     * apply()'s contract in this class.
     *
     * @return boolean true if the rollback was applied, false if refused or failed
     */
    public function rollback() {

        $current_version = $this->c->get( $this->module_name, 'schema_version' );

        // check to see that you are rolling back either an update that was successfully applied or one that might have failed.
        // we dont want people applying rollbacks out of sequence.
        if ($current_version === $this->schema_version || $current_version === $this->schema_version - 1) {

            // Said before it runs, so it reads as a warning rather than a post-mortem.
            if ( $this->down_does_not_restore ) {

                $this->e->notice(sprintf('Rolling back update %s restores the schema but not: %s',
                    $this->schema_version, $this->down_does_not_restore));
            }

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
    public function pre() {

        return true;
    }

    /**
     * Abstract Post-update hook
     *
     * @return boolean
     */
    public function post() {

        return true;
    }

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
     * Asked of Db::listColumns() and compared exactly: a LIKE pattern would
     * read the underscore in a column name as a wildcard.
     *
     * @param  object $entity     a table entity
     * @param  string $column
     * @param  string $definition the column's SQL type, for a column the entity
     *                            no longer declares; '' to ask the entity
     * @return bool   false only when the column is genuinely missing and could
     *                not be added
     */
    protected function addColumnIfMissing( $entity, $column, $definition = '' ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( in_array( $column, $db->listColumns( $entity->getTableName() ), true ) ) {

            return true;
        }

        /*
         * AN EXPLICIT DEFINITION IS FOR A COLUMN THE ENTITY NO LONGER DECLARES.
         *
         * Going through the entity is right while the column is still part of
         * the shape: one declaration, and the update cannot drift from it. But
         * an update is permanent and a shape is not -- a LATER update may drop
         * the column, and from then on this one is asking the entity to
         * describe something it has deliberately forgotten. getColumn() throws,
         * and would break the whole upgrade path from before that version.
         *
         * Update037 is the case: it adds prev_event_ts, and Update046 removes
         * it. The mirror of Update046::down() having to spell its types out for
         * exactly the same reason.
         */
        if ( $definition !== '' ) {

            return $db->addColumn( $entity->getTableName(), $column, $definition ) !== false;
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

        if ( ! in_array( $column, $db->listColumns( $entity->getTableName() ), true ) ) {

            return true;
        }

        return $entity->dropColumn( $column ) !== false;
    }

    /**
     * Applies the schema change.
     *
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        return false;
    }

    /**
     * Abstract Method for reversing an update
     *
     * @return boolean
     */
    public function down() {

        return false;
    }

}
