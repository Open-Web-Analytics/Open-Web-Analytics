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

if(!class_exists('owa_observer')) {
    require_once(OWA_DIR.'owa_observer.php');
}
require_once(OWA_DIR.'owa_lib.php');

/**
 * Session Commerce Summary Event handlers
 *
 * Listens for commerce.transaction event and does an idempotent update of the session's
 * commerce realted summary columns.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006-2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_sessionCommerceSummaryHandlers extends owa_observer {

    /**
     * Notify handler method
     *
     * @param     object    $event
     * @access     public
     * @return    boolean
     */
    function notify($event) {

        $pk = $event->get( 'session_id' );

        // just in case events slip thorugh that have no session_id
        // look for the original session id param
        if ( ! $pk ) {

            $pk = $event->get( 'original_session_id' );

            if ( $pk ) {

                $event->set('session_id', $pk);
            }
        }

        if ( $event->get( 'session_id' ) ) {

            $s = owa_coreAPI::entityFactory( 'base.session' );

            $s->getByPk( 'id', $pk );
            $id = $s->get('id');

            if ($id) {
                // summarze the transaction
                $summary = owa_coreAPI::summarize(array(
                    'entity'        => 'base.commerce_transaction_fact',
                    'columns'        => array(
                            'id'         => 'count',
                            'total_revenue'        => 'sum',
                            'tax_revenue'        => 'sum',
                            'shipping_revenue'    => 'sum'),
                    'constraints'    => array( 'session_id' => $id ) ) );

                $s->set( 'commerce_trans_count', $summary['id_count'] );
                $s->set( 'commerce_trans_revenue', $summary['total_revenue_sum'] );
                $s->set( 'commerce_tax_revenue', $summary['tax_revenue_sum'] );
                $s->set( 'commerce_shipping_revenue', $summary['shipping_revenue_sum'] );

                // check for items and summarize if needed.
                $items = $event->get('ct_line_items');

                if ( ! empty( $items ) ) {
                    $summary = owa_coreAPI::summarize(array(
                    'entity'        => 'base.commerce_line_item_fact',
                    'columns'        => array(
                            'sku'                 => 'count_distinct',
                            'item_revenue'        => 'sum',
                            'quantity'            => 'sum'),
                    'constraints'    => array( 'session_id' => $id ) ) );

                    $s->set( 'commerce_items_count', $summary['sku_dcount'] );
                    $s->set( 'commerce_items_revenue', $summary['item_revenue_sum'] );
                    $s->set( 'commerce_items_quantity', $summary['quantity_sum'] );
                }

                $ret = $s->update();

                if ($ret) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                owa_coreAPI::debug('Not Updating session commerce transaction properties. Session does not exist yet.');
                return OWA_EHS_EVENT_FAILED;
            }

        } else {

            owa_coreAPI::debug('Not Updating session commerce transaction properties. Session_id not present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>