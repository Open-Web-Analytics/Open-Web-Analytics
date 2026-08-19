<?php
namespace OWA\Module\Base\Handler;


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

class DocumentHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        // The PRIOR page is referenced by this event without being what the
        // event is about. RequestHandlers hashes it into prior_document_id, and
        // four reporting dimensions -- priorPageUrl, priorPagePath,
        // priorPageTitle, priorPageType -- join owa_document through that key.
        // Nothing has ever created the row they join to, so those dimensions
        // returned nothing for any prior page that was never itself tracked:
        // 7.2% of the rows carrying the key on one installation, 2.0% on
        // another, steady across fifteen years.
        //
        // The URL is right here on the event and was simply being discarded.
        // setPriorPage only accepts a referrer whose host matches the site's,
        // so this cannot pull in other people's pages.
        $this->ensureDocumentFor( $event, $event->get( 'prior_page' ) );

        if ( $event->get( 'document_id' ) || $event->get( 'page_url' ) ) {

            // create entity
            /* @var owa_document $d */
            $d = \OWA\Core\CoreAPI::entityFactory( 'base.document' );

            // get document id from event
            $id = $event->get( 'document_id' );

            // if no document_id present attempt to make one from the page_url property
            if ( ! $id ) {

                $page_url = $event->get( 'page_url' );

                if ( $page_url ) {

                    $id = $d->generateId( $page_url );
                } else {

                    \OWA\Core\CoreAPI::debug( 'Not persisting Document, no page_url or document_id event property found.' );

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

                $d->detectIdCollision( 'url', $event->get( 'page_url' ) );

                if (\OWA\Core\CoreAPI::getSetting('base', 'allow_slowly_changing_dimensions') &&
                    in_array(get_class($d), \OWA\Core\CoreAPI::getSetting('base', 'slowly_changing_dimension_entities'))
                ) {
                    $updated = false;

                    $pageTitle = $event->get('page_title');
                    $currentTitle = $d->get('page_title');
                    if ($currentTitle !== $pageTitle) {
                        $d->set('page_title', $pageTitle);
                        $updated = true;
                        \OWA\Core\CoreAPI::debug(sprintf('Page title changed from %s to %s', $currentTitle, $pageTitle));
                    }

                    $pageType = $event->get('page_type');
                    $currentType = $d->get('page_type');
                    if ($currentType !== $pageType) {
                        $d->set('page_type', $pageType);
                        $updated = true;
                        \OWA\Core\CoreAPI::debug(sprintf('Page type changed from %s to %s', $currentTitle, $pageTitle));
                    }

                    if ($updated) {
                        $d->save();
                    }
                }

                \OWA\Core\CoreAPI::debug('Not logging Document, already exists');
                return OWA_EHS_EVENT_HANDLED;
            }

        } else {

            \OWA\Core\CoreAPI::notice('Not persisting Document dimension. document id or page url are missing from event.');

            return OWA_EHS_EVENT_HANDLED;
        }
    }


    /**
     * Make sure a document row exists for a URL this event merely references.
     *
     * Creates a SPARSE row: the url and the uri, which are all that can honestly
     * be known about a page nobody has tracked. Title and type stay empty rather
     * than being guessed, so a report showing them displays nothing instead of
     * something invented.
     *
     * Never touches an existing row. A page that has been tracked properly keeps
     * the title and type its own pageview recorded, and a later reference to it
     * must not flatten those.
     *
     * The id is derived exactly as the fact-side key is, or the row would not be
     * the one the key points at.
     *
     * @param object $event
     * @param string $url
     * @return void
     */
    protected function ensureDocumentFor( $event, $url ) {

        if ( ! $url || $url === '(not set)' ) {

            return;
        }

        $d  = \OWA\Core\CoreAPI::entityFactory( 'base.document' );
        $id = $d->generateId( $url );

        $d->load( $id );

        if ( $d->wasPersisted() ) {

            return;
        }

        $d->set( 'id', $id );
        $d->set( 'url', $url );
        $d->set( 'uri', $this->uriFor( $url ) );

        $d->create();

        \OWA\Core\CoreAPI::debug( sprintf( 'Created a referenced-only document row for %s', $url ) );
    }

    /**
     * The uri a tracked pageview would have recorded for this URL.
     *
     * Mirrors owa_trackingEventHelpers::derivePageUri so a row created here is
     * shaped like one created from a real pageview.
     *
     * @param string $url
     * @return string
     */
    protected function uriFor( $url ) {

        $parts = parse_url( (string) $url );

        $path = ( ! empty( $parts['path'] ) ) ? $parts['path'] : '/';

        return ( ! empty( $parts['query'] ) ) ? sprintf( '%s?%s', $path, $parts['query'] ) : $path;
    }
}
