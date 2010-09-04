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
 * Commerce Transaction Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006-2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_commerceTransactionHandlers extends owa_observer {
    	
    /**
     * Notify handler method
     *
     * @param 	object $event
     * @access 	public
     */
    function notify($event) {
		
    	$ct = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
		$pk = $ct->generateId( $event->get( 'ct_order_id' ) );
		$ct->getByPk( 'id', $pk );
		$id = $ct->get('id'); 
		
		if (!$id) {
			
			// todo: make inbound_session_id irrelevent by fixing session_id assignment upstream
			// extract site specific state from session store
			$state = owa_coreAPI::getStateParam('ss_'.$event->get('site_id'), 's');
			$event->set('session_id', $state);
			
			$ct->setProperties($event->getProperties());
			
			$ct->set( 'id', $pk ); 
			
			// Generate Location Id. Location data is comming from user input
			$location_id_string  = trim( strtolower( $event->get( 'country' ) ) );
			$location_id_string .= trim( strtolower ( $event->get( 'state' ) ) );
			$location_id_string .= trim( strtolower( $event->get( 'city' ) ) ); 
			$ct->set( 'location_id', $ct->generateId( $location_id_string ) );
			// set entity properties
			$ct->set( 'order_id', trim( $event->get( 'ct_order_id' ) ) );
			$ct->set( 'order_source', trim( strtolower( $event->get( 'ct_order_source' ) ) ) );
			$ct->set( 'gateway', trim( strtolower( $event->get( 'ct_gateway' ) ) ) );
			$ct->set( 'total_revenue', owa_lib::prepareCurrencyValue( round( $event->get( 'ct_total' ), 2 ) ) );
			$ct->set( 'tax_revenue', owa_lib::prepareCurrencyValue( round( $event->get( 'ct_tax' ), 2 ) ) );
			$ct->set( 'shipping_revenue', owa_lib::prepareCurrencyValue( round( $event->get( 'ct_shipping' ), 2 ) ) );
			$ret = $ct->create();
			
			// persist line items
			if ($ret) {
				$items = $event->get('ct_line_items');
				if ( $items ) {
					
					foreach ($items as $item) {
						$this->persistLineItem($item, $event);
					}
				}
			}
			
			if ($ret) {
				$dispatch = owa_coreAPI::getEventDispatch();
				$sce = $dispatch->makeEvent( 'commerce.transaction_persisted' );
				$sce->setProperties( $event->getProperties() );
				$dispatch->asyncNotify( $sce );
			}
			
		} else {
		
			owa_coreAPI::debug('Not Persisting. Transaction already exists');
		}	
    }
    
    function persistLineItem($event, $parent) {
    	
    	$ct = owa_coreAPI::entityFactory('base.commerce_line_item_fact');
		$pk = $ct->generateId( $event->get( 'li_order_id' ) . $event->get( 'li_sku' ) );
		$ct->getByPk( 'id', $pk );
		$id = $ct->get( 'id' ); 
		
		if ( ! $id ) {
			
			$ct->setProperties( $parent->getProperties() );
			
			$ct->set( 'id', $pk ); 
			
			// Generate Location Id. Location data is comming from user input
			$ct->set( 'order_id', trim( $event->get( 'li_order_id' ) ) );
			$ct->set( 'sku', trim( $event->get( 'li_sku' ) ) );
			$ct->set( 'product_name', trim( strtolower( $event->get( 'li_product_name' ) ) ) );
			$ct->set( 'category', $event->get( 'li_category' ) );
			$ct->set( 'unit_price', owa_lib::prepareCurrencyValue( round($event->get( 'li_unit_price' ), 2 ) ) );
			$ct->set( 'quantity', round( $event->get( 'li_quantity' ) ) );
			$revenue = round( $event->get( 'li_quantity' ) * $event->get( 'li_unit_price' ), 2 );
			$ct->set( 'item_revenue', owa_lib::prepareCurrencyValue( $revenue ) );
			$ret = $ct->create();
			
			if ($ret) {
				return true;
			} else {
				return false;
			}
			
		} else {
		
			owa_coreAPI::debug('Not Persisting. line item already exists');
			return false;
		}	
    }
}

?>