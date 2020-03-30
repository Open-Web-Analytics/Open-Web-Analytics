<?php

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

if ( ! class_exists( 'owa_observer' ) ) {
    require_once( OWA_BASE_DIR.'owa_observer.php' );
}

/**
 * OWA Document Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_documentHandlers extends owa_observer {

    /**
     * Notify Event Handler
     *
     * @param     unknown_type $event
     * @access     public
     */
    function notify($event) {

        if ( $event->get( 'document_id' ) || $event->get( 'page_url' ) ) {

            // create entity
            $d = owa_coreAPI::entityFactory( 'base.document' );

            // get document id from event
            $id = $event->get( 'document_id' );

            // if no document_id present attempt to make one from the page_url property
            if ( ! $id ) {

                $page_url = $event->get( 'page_url' );

                if ( $page_url ) {

                    $id = $d->generateId( $page_url );
                } else {

                    owa_coreAPI::debug( 'Not persisting Document, no page_url or document_id event property found.' );

                    return OWA_EHS_EVENT_HANDLED;
                }
            }

            $d->load( $id );

            if ( ! $d->wasPersisted() ) {

                $d->setProperties( $event->getProperties() );

                $d->set( 'url', $event->get( 'page_url' ) );

                $d->set( 'uri', $event->get( 'page_uri' ) );

                $d->set( 'id', $id );

                $ret = $d->create();

                if ( $ret ) {

                    return OWA_EHS_EVENT_HANDLED;

                } else {

                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                owa_coreAPI::debug('Not logging Document, already exists');
                return OWA_EHS_EVENT_HANDLED;
            }

        } else {

            owa_coreAPI::notice('Not persisting Document dimension. document id or page url are missing from event.');

            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>