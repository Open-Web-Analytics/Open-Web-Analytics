<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Shared behaviour for schedule-run and schedule-status.
 *
 * The job registry comes from the module registrations overlaid with
 * OWA_SCHEDULED_JOBS, and the state rows come from owa_scheduled_job. Both
 * commands need to resolve the same jobs the same way, so that lives here
 * rather than being written twice and drifting.
 */
abstract class SchedulerCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /**
     * Every registered job, keyed by name.
     *
     * @return array
     */
    protected function jobs() {

        return \OWA\Module\Base\Classes\JobStatus::jobs();
    }

    /**
     * The timezone schedules are read in.
     *
     * The installation's own, the same one reports render in, so "04:00" means
     * what the person reading the reports means by it.
     *
     * @return string
     */
    protected function timezone() {

        return \OWA\Module\Base\Classes\JobStatus::timezone();
    }

    /**
     * A job's parsed schedule, or null when it is off or unreadable.
     *
     * @param array $job
     * @return array|null
     */
    protected function parsedSchedule( $job ) {

        return \OWA\Module\Base\Classes\JobStatus::parsedSchedule( $job );
    }

    /**
     * Is this job switched off rather than merely unreadable?
     *
     * @param array $job
     * @return bool
     */
    protected function isDisabled( $job ) {

        return \OWA\Module\Base\Classes\JobStatus::isDisabled( $job );
    }

    /**
     * The state row for a job, or null.
     *
     * READ BY job_name, NEVER BY id. Lib::setStringGuid() branches on the
     * id scheme in force, so a change to it on a live installation would
     * change what generateId() returns for the same name -- a read by id would
     * then miss the existing row and insert a second one, colliding on job_name
     * but not on id.
     *
     * @param string $name
     * @return \OWA\Core\Entity|null
     */
    protected function state( $name ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' );

        $entity->getByColumn( 'job_name', $name );

        return $entity->wasPersisted() ? $entity : null;
    }

    /**
     * Every state row, keyed by job name.
     *
     * @return array
     */
    protected function allState() {

        return \OWA\Module\Base\Classes\JobStatus::allState();
    }

    /**
     * A timestamp as a person reads it, in the installation's zone.
     *
     * @param int|null $ts
     * @param string   $absent
     * @return string
     */
    protected function readable( $ts, $absent = 'never' ) {

        return \OWA\Module\Base\Classes\JobStatus::readable( $ts, $absent );
    }

    /**
     * Params rendered for the record, never for re-parsing.
     *
     * '-' rather than '' when there are none, because Entity::set() drops falsy
     * values and an empty string would silently fail to write.
     *
     * @param array $params
     * @return string
     */
    protected function encodeParams( $params ) {

        if ( ! $params ) {

            return '-';
        }

        $pairs = array();

        foreach ( (array) $params as $k => $v ) {

            $pairs[] = $k . '=' . ( is_scalar( $v ) ? $v : json_encode( $v ) );
        }

        return substr( implode( ' ', $pairs ), 0, 250 );
    }
}
