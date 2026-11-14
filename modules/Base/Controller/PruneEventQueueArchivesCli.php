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
// $Id$
//


/**
 * Prune Event Queues Archive CLI Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.6.0
 */

class PruneEventQueueArchivesCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Deletes archived files of the file-based event queues.',
            'arguments'   => array(
                'queues=<name>[,<name>...]' => 'The queues to prune. Defaults to every registered queue.',
                'interval=<seconds>'        => 'Age beyond which an archive is deleted. Defaults to 86400.',
                'scheduled=1'               => 'Set by the scheduler. Deletes only archives older than raw_retention_months, and none when it is not set; interval= is ignored.',
            ),
        );
    }

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        parent::__construct($params);
    }

    /**
     *   cli.php cmd=prune-event-queue-archives [queues=a,b] [interval=<seconds>]
     *
     * Archived batches older than interval (a day by default), of every
     * registered queue that keeps an archive, or of those named.
     *
     * Scheduled (prune-tracker-ingest-archive), the age is raw's retention
     * window instead: an archived batch is kept as long as the events in it
     * would be, so with raw_retention_months unset nothing is deleted.
     */
    function action() {

        if ( $this->getParam( 'scheduled' ) ) {

            $keep_from = \OWA\Module\Base\Classes\Retention::rawCutoffTimestamp();

            if ( $keep_from === null ) {

                return;
            }

            $interval = max( 0, time() - $keep_from );

        } else {

            $interval = (int) ( $this->getParam( 'interval' ) ?: 86400 );
        }

        $queues = $this->getParam( 'queues' )
            ? explode( ',', (string) $this->getParam( 'queues' ) )
            : array_keys( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'event_queues' ) );

        foreach ( $queues as $queue_name ) {

            // The intake is built from its setting, not its registration.
            $q = $queue_name === \OWA\Module\Base\Classes\TrackerIngest::QUEUE
                ? \OWA\Module\Base\Classes\TrackerIngest::queue()
                : \OWA\Core\CoreAPI::getEventQueue( $queue_name );

            // The intake's file fallback (TrackerIngest::fallback()) archives what it forwards.
            $fallback = $queue_name === \OWA\Module\Base\Classes\TrackerIngest::QUEUE
                ? \OWA\Module\Base\Classes\TrackerIngest::fallback() : null;

            foreach ( array( $q, $fallback ) as $each ) {

                if ( $each && method_exists( $each, 'pruneArchive' ) ) {

                    $pruned = (int) $each->pruneArchive( $interval );

                    \OWA\Core\CoreAPI::notice( sprintf( 'Pruned %d archived file%s of event queue %s.',
                        $pruned, $pruned === 1 ? '' : 's', $queue_name ) );
                }
            }
        }
    }
}

?>