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
     */
    function action() {

        $interval = (int) ( $this->getParam( 'interval' ) ?: 86400 );

        $queues = $this->getParam( 'queues' )
            ? explode( ',', (string) $this->getParam( 'queues' ) )
            : array_keys( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'event_queues' ) );

        foreach ( $queues as $queue_name ) {

            // The intake is built from its setting, not its registration.
            $q = $queue_name === \OWA\Module\Base\Classes\TrackerIngest::QUEUE
                ? \OWA\Module\Base\Classes\TrackerIngest::queue()
                : \OWA\Core\CoreAPI::getEventQueue( $queue_name );

            if ( method_exists( $q, 'pruneArchive' ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Pruned %d archived file(s) of event queue %s.',
                    (int) $q->pruneArchive( $interval ), $queue_name ) );
            }
        }
    }
}

?>