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


namespace OWA\Module\Base\Entity;


/**
 * Commerce Transaction Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class CommerceTransactionFact extends \OWA\Core\Entity\FactTable {

    function __construct() {

        $this->setTableName('commerce_transaction_fact');

        // set common fact table columns
        $parent_columns = parent::__construct();

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }

        // move to abstract
        //$id = new \owa_dbColumn('id', OWA_DTD_BIGINT);
        //$id->setPrimaryKey();
        //$this->setProperty($id);

        // move to abstract
        //$visitor_id = new \owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
        //$visitor_id->setForeignKey('base.visitor');
        //$this->setProperty($visitor_id);

        // move to abstract
        //$session_id = new \owa_dbColumn('session_id', OWA_DTD_BIGINT);
        //$session_id->setForeignKey('base.session');
        //$this->setProperty($session_id);

        $document_id = new \OWA\Module\Base\Classes\DbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        // move to abstract
        //$site_id = new \owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
        //$site_id->setForeignKey('base.site', 'site_id');
        //$this->setProperty($site_id);

        // move to abstract
        //$ua_id = new \owa_dbColumn('ua_id', OWA_DTD_BIGINT);
        //$ua_id->setForeignKey('base.ua');
        //$this->setProperty($ua_id);

        // move to abstract
        //$host_id = new \owa_dbColumn('host_id', OWA_DTD_BIGINT);
        //$host_id->setForeignKey('base.host');
        //$this->setProperty($host_id);

        // move to abstract
        //$os_id = new \owa_dbColumn('os_id', OWA_DTD_BIGINT);
        //$os_id->setForeignKey('base.os');
        //$this->setProperty($os_id);

        // move to abstract
        //$location_id = new \owa_dbColumn('location_id', OWA_DTD_BIGINT);
        //$location_id->setForeignKey('base.location_dim');
        //$this->setProperty($location_id);

        // move to abstract
        //$medium = new \owa_dbColumn('medium',OWA_DTD_VARCHAR255);
        //$this->setProperty($medium);

        // move to abstract
        //$source_id = new \owa_dbColumn('source_id', OWA_DTD_BIGINT);
        //$source_id->setForeignKey('base.source_dim');
        //$this->setProperty($source_id);

        // move to abstract
        //$ad_id = new \owa_dbColumn('ad_id', OWA_DTD_BIGINT);
        //$ad_id->setForeignKey('base.ad_dim');
        //$this->setProperty($ad_id);

        // move to abstract
        //$campaign_id = new \owa_dbColumn('campaign_id', OWA_DTD_BIGINT);
        //$campaign_id->setForeignKey('base.campaign_dim');
        //$this->setProperty($campaign_id);

        // move to abstract
        //$referring_search_term_id = new \owa_dbColumn('referring_search_term_id', OWA_DTD_BIGINT);
        //$referring_search_term_id->setForeignKey('base.search_term_dim');
        //$this->setProperty($referring_search_term_id);

        // move to abstract
        //$referer_id = new \owa_dbColumn('referer_id', OWA_DTD_BIGINT);
        //$referer_id->setForeignKey('base.referer');
        //$this->setProperty($referer_id);

        // move to abstract
        //$timestamp = new \owa_dbColumn('timestamp', OWA_DTD_INT);
        //$this->setProperty($timestamp);

        // move to abstract
        //$yyyymmdd = new \owa_dbColumn('yyyymmdd', OWA_DTD_INT);
        //$this->setProperty($yyyymmdd);

        /*
         * Billing address: an attribute of the TRANSACTION, not of the visitor.
         *
         * Deliberately not in the geolocation dimension. These used to arrive
         * as country/city/state -- the names of the server-derived geolocation
         * properties -- and were used to build this row's location_id, so a
         * transaction's location meant the billing address while every other
         * event type meant where the visitor's IP said they were. The two were
         * indistinguishable once written. Different facts, different homes.
         */
        $billing_country = new \OWA\Module\Base\Classes\DbColumn('billing_country', OWA_DTD_VARCHAR255);
        $this->setProperty($billing_country);

        $billing_state = new \OWA\Module\Base\Classes\DbColumn('billing_state', OWA_DTD_VARCHAR255);
        $this->setProperty($billing_state);

        $billing_city = new \OWA\Module\Base\Classes\DbColumn('billing_city', OWA_DTD_VARCHAR255);
        $this->setProperty($billing_city);

        $order_id = new \OWA\Module\Base\Classes\DbColumn('order_id', OWA_DTD_VARCHAR255);
        $order_id->setIndex();
        $this->setProperty($order_id);

        $order_source = new \OWA\Module\Base\Classes\DbColumn('order_source', OWA_DTD_VARCHAR255);
        $this->setProperty($order_source);

        $gateway = new \OWA\Module\Base\Classes\DbColumn('gateway', OWA_DTD_VARCHAR255);
        $this->setProperty($gateway);

        $total = new \OWA\Module\Base\Classes\DbColumn('total_revenue', OWA_DTD_BIGINT);
        $this->setProperty($total);

        $tax = new \OWA\Module\Base\Classes\DbColumn('tax_revenue', OWA_DTD_BIGINT);
        $this->setProperty($tax);

        $shipping = new \OWA\Module\Base\Classes\DbColumn('shipping_revenue', OWA_DTD_BIGINT);
        $this->setProperty($shipping);

        // move to abstract
        //$days_since_first_session = new \owa_dbColumn('days_since_first_session', OWA_DTD_INT);
        //$this->setProperty($days_since_first_session);

        // move to abstract
        //$nps = new \owa_dbColumn('num_prior_sessions', OWA_DTD_INT);
        //$this->setProperty($nps);
    }
}

?>