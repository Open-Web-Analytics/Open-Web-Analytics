<?php

namespace OWA\Module\Base\Controller;

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

/**
 * Repair city and region names that were stored as double-encoded UTF-8.
 *
 *     php cli.php cmd=repair-geo-encoding dry-run=1
 *     php cli.php cmd=repair-geo-encoding
 *
 * The geolocation reader used to convert every name it read from MaxMind from
 * ISO-8859-1 to UTF-8, when MaxMind stores names as UTF-8 already. So names were
 * encoded twice on the way in: "München" was stored as "MÃ¼nchen". The reader no
 * longer does this, which fixes new lookups and leaves every row already written
 * wrong. Issue #742.
 *
 * A COMMAND RATHER THAN AN Update CLASS, on purpose. An Update runs on every
 * upgrade, unattended, over whatever an installation happens to hold; this
 * rewrites stored text on a judgement about what it used to be. That judgement
 * is conservative (see GeoEncodingRepair) but it is still a judgement, so it
 * belongs somewhere an operator opts into, can rehearse with dry-run=1, and can
 * read the list of changes before agreeing to them.
 *
 * ONLY location_dim. Every geo reporting dimension resolves through that table,
 * so it is the only place a wrong name reaches a report. host.city, host.country,
 * session.city and session.country hold denormalised copies that no registered
 * dimension reads; scanning session, which is one of the largest tables an
 * installation has, to fix text nothing displays would be a poor trade.
 *
 * IDS ARE LEFT ALONE. location_dim.id is generateId(country . city) over the
 * values as they were when the row was created, so repairing the text leaves the
 * id derived from the old spelling. That is correct: fact rows join on the id,
 * and reports group on the text, so the display is fixed and no fact row is
 * orphaned. Recomputing ids would strand every fact row pointing at the old one.
 * The cost is that a row repaired here and a row created after the fix can carry
 * the same name under two ids, which reports collapse because they group by name.
 *
 * In Base rather than in the MaxmindGeoip module, because the rows are Base's and
 * the command has to stay available to an operator who has since deactivated the
 * module that wrote them.
 */
class RepairGeoEncodingCli extends \OWA\Core\Controller\Cli {

    /**
     * How many rows to examine in one run.
     *
     * Bounded because this is a table scan and an installation's location_dim
     * grows with the number of distinct places its visitors come from. Re-run
     * until it reports nothing left; the command is idempotent, since a repaired
     * value is no longer double-encoded and is skipped on the next pass.
     */
    const DEFAULT_LIMIT = 5000;

    /** The columns that hold a name a report will display. */
    const COLUMNS = array( 'country', 'state', 'city' );

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );

        parent::__construct( $params );
    }

    function action() {

        $dry_run = (bool) $this->getParam( 'dry-run' );
        $limit   = (int) $this->getParam( 'limit' );

        if ( $limit < 1 ) {

            $limit = self::DEFAULT_LIMIT;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.location_dim' );
        $table  = $entity->getTableName();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $table );
        $db->selectColumn( 'id, ' . implode( ', ', self::COLUMNS ) );
        $db->limit( $limit );

        $rows = $db->getAllRows();

        if ( ! is_array( $rows ) ) {

            return $this->fail( sprintf(
                'Could not read %s. Nothing has been changed.', $table ) );
        }

        $examined = count( $rows );
        $repairs  = $this->planRepairs( $rows );

        if ( ! $repairs ) {

            $this->write( sprintf(
                'Examined %d row%s in %s. Nothing is double-encoded.',
                $examined, $examined === 1 ? '' : 's', $table ) );

            return;
        }

        // Named before anything is written, so dry-run=1 and the real run report
        // the same thing and an operator can compare them.
        foreach ( $repairs as $repair ) {

            $this->write( sprintf( '  %s %s: %s -> %s',
                $repair['id'], $repair['column'], $repair['from'], $repair['to'] ) );
        }

        if ( $dry_run ) {

            $this->write( sprintf(
                'Examined %d row%s in %s. %d value%s would be repaired. Nothing was written.',
                $examined, $examined === 1 ? '' : 's', $table,
                count( $repairs ), count( $repairs ) === 1 ? '' : 's' ) );

            return;
        }

        $written = $this->applyRepairs( $repairs );

        if ( $written === false ) {

            return $this->fail( sprintf(
                'Repairing %s failed part way through. %d value%s were written before it stopped; '
              . 'the command is safe to re-run.', $table,
                $this->written_count, $this->written_count === 1 ? '' : 's' ) );
        }

        $this->write( sprintf(
            'Examined %d row%s in %s. Repaired %d value%s.%s',
            $examined, $examined === 1 ? '' : 's', $table,
            $written, $written === 1 ? '' : 's',
            $examined === $limit
                ? ' The row limit was reached, so run it again to continue.' : '' ) );

        return;
    }

    /** @var int rows written before a failure, for the message */
    protected $written_count = 0;

    /**
     * Every value in these rows that is double-encoded, and what it should be.
     *
     * Separate from the write so the list can be reported identically whether or
     * not anything is going to be written.
     *
     * @param array $rows
     * @return array<array{id: string, column: string, from: string, to: string}>
     */
    protected function planRepairs( $rows ) {

        $repairs = array();

        foreach ( $rows as $row ) {

            foreach ( self::COLUMNS as $column ) {

                if ( ! isset( $row[ $column ] ) ) {

                    continue;
                }

                $repaired = \OWA\Module\Base\Classes\GeoEncodingRepair::repair( $row[ $column ] );

                if ( $repaired === null ) {

                    continue;
                }

                $repairs[] = array(
                    'id'     => $row['id'],
                    'column' => $column,
                    'from'   => $row[ $column ],
                    'to'     => $repaired,
                );
            }
        }

        return $repairs;
    }

    /**
     * Write the planned repairs, one row at a time through the entity layer.
     *
     * Per row rather than one statement per column, because the entity layer is
     * what knows how to write this table and a repair is not worth a hand-rolled
     * UPDATE. The volume is bounded by the row limit and this is a maintenance
     * command, so the cost is an operator's patience rather than a visitor's.
     *
     * @param array $repairs
     * @return int|false values written, or false if a write was rejected
     */
    protected function applyRepairs( $repairs ) {

        $this->written_count = 0;

        // Group by row, so a row with a wrong city AND a wrong state is written
        // once rather than twice.
        $by_row = array();

        foreach ( $repairs as $repair ) {

            $by_row[ $repair['id'] ][ $repair['column'] ] = $repair['to'];
        }

        foreach ( $by_row as $id => $values ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.location_dim' );
            $entity->getByPk( 'id', $id );

            if ( ! $entity->get( 'id' ) ) {

                // Vanished between the read and the write. Not a failure: the row
                // this would have repaired no longer exists.
                continue;
            }

            foreach ( $values as $column => $value ) {

                $entity->set( $column, $value );
            }

            if ( ! $entity->update() ) {

                return false;
            }

            $this->written_count += count( $values );
        }

        return $this->written_count;
    }
}
