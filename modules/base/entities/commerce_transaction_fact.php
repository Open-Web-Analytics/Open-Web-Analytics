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

if ( ! class_exists( 'owa_factTable' ) ) {
    require_once( OWA_BASE_CLASS_DIR . 'factTable.php');
}

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

class owa_commerce_transaction_fact extends owa_factTable {

    function __construct() {

        $this->setTableName('commerce_transaction_fact');

        // set common fact table columns
        $parent_columns = parent::__construct();

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }

        // move to abstract
        //$id = new owa_dbColumn('id', OWA_DTD_BIGINT);
        //$id->setPrimaryKey();
        //$this->setProperty($id);

        // move to abstract
        //$visitor_id = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
        //$visitor_id->setForeignKey('base.visitor');
        //$this->setProperty($visitor_id);

        // move to abstract
        //$session_id = new owa_dbColumn('session_id', OWA_DTD_BIGINT);
        //$session_id->setForeignKey('base.session');
        //$this->setProperty($session_id);

        $document_id = new owa_dbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        // move to abstract
        //$site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
        //$site_id->setForeignKey('base.site', 'site_id');
        //$this->setProperty($site_id);

        // move to abstract
        //$ua_id = new owa_dbColumn('ua_id', OWA_DTD_BIGINT);
        //$ua_id->setForeignKey('base.ua');
        //$this->setProperty($ua_id);

        // move to abstract
        //$host_id = new owa_dbColumn('host_id', OWA_DTD_BIGINT);
        //$host_id->setForeignKey('base.host');
        //$this->setProperty($host_id);

        // move to abstract
        //$os_id = new owa_dbColumn('os_id', OWA_DTD_BIGINT);
        //$os_id->setForeignKey('base.os');
        //$this->setProperty($os_id);

        // move to abstract
        //$location_id = new owa_dbColumn('location_id', OWA_DTD_BIGINT);
        //$location_id->setForeignKey('base.location_dim');
        //$this->setProperty($location_id);

        // move to abstract
        //$medium = new owa_dbColumn('medium',OWA_DTD_VARCHAR255);
        //$this->setProperty($medium);

        // move to abstract
        //$source_id = new owa_dbColumn('source_id', OWA_DTD_BIGINT);
        //$source_id->setForeignKey('base.source_dim');
        //$this->setProperty($source_id);

        // move to abstract
        //$ad_id = new owa_dbColumn('ad_id', OWA_DTD_BIGINT);
        //$ad_id->setForeignKey('base.ad_dim');
        //$this->setProperty($ad_id);

        // move to abstract
        //$campaign_id = new owa_dbColumn('campaign_id', OWA_DTD_BIGINT);
        //$campaign_id->setForeignKey('base.campaign_dim');
        //$this->setProperty($campaign_id);

        // move to abstract
        //$referring_search_term_id = new owa_dbColumn('referring_search_term_id', OWA_DTD_BIGINT);
        //$referring_search_term_id->setForeignKey('base.search_term_dim');
        //$this->setProperty($referring_search_term_id);

        // move to abstract
        //$referer_id = new owa_dbColumn('referer_id', OWA_DTD_BIGINT);
        //$referer_id->setForeignKey('base.referer');
        //$this->setProperty($referer_id);

        // move to abstract
        //$timestamp = new owa_dbColumn('timestamp', OWA_DTD_INT);
        //$this->setProperty($timestamp);

        // move to abstract
        //$yyyymmdd = new owa_dbColumn('yyyymmdd', OWA_DTD_INT);
        //$this->setProperty($yyyymmdd);

        $order_id = new owa_dbColumn('order_id', OWA_DTD_VARCHAR255);
        $order_id->setIndex();
        $this->setProperty($order_id);

        $order_source = new owa_dbColumn('order_source', OWA_DTD_VARCHAR255);
        $this->setProperty($order_source);

        $gateway = new owa_dbColumn('gateway', OWA_DTD_VARCHAR255);
        $this->setProperty($gateway);

        $total = new owa_dbColumn('total_revenue', OWA_DTD_BIGINT);
        $this->setProperty($total);

        $tax = new owa_dbColumn('tax_revenue', OWA_DTD_BIGINT);
        $this->setProperty($tax);

        $shipping = new owa_dbColumn('shipping_revenue', OWA_DTD_BIGINT);
        $this->setProperty($shipping);

        // move to abstract
        //$days_since_first_session = new owa_dbColumn('days_since_first_session', OWA_DTD_INT);
        //$this->setProperty($days_since_first_session);

        // move to abstract
        //$nps = new owa_dbColumn('num_prior_sessions', OWA_DTD_INT);
        //$this->setProperty($nps);
    }
}

?>