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

require_once( OWA_BASE_CLASS_DIR . 'factTable.php');

/**
 * Session Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_session extends owa_factTable {

    function __construct() {

        // table name
        $this->setTableName('session');
        $this->setSummaryLevel(1);

        // set common fact table columns
        $parent_columns = parent::__construct();

        // remove the session id from parent col list
        // as it's a duplicate to the id column for this entity.
        if (isset( $parent_columns['session_id'] ) ) {
            unset( $parent_columns['session_id'] );
        }

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }


        // set remaining columns
        if ( method_exists( $this, 'getEntityPropertyList' ) ) {

            return $this->init();
        }
    }


    function getEntityPropertyList() {

        $properties = array (

            'user_email' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop
            'hour' => array(

                'type'				=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT2'
            ),

            //drop
            'minute' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT2'
            ),

            'last_req' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),

            'num_pageviews' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            //drop, not being used.
            'num_comments' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),


            'is_bounce' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            'prior_session_lastreq' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),

            'prior_session_id' => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),


            'time_sinse_priorsession'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            //drop?
            'prior_session_year'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT4'
            ),

            //drop?
            'prior_session_month'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop
            'prior_session_day'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT2'
            ),

            //drop
            'prior_session_dayofweek'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            //drop
            'prior_session_hour'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT2'
            ),

            //drop
            'prior_session_minute'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT2'
            ),

            //drop?
            'os'             => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            'first_page_id'     => array(

                'type'             	=> 'foreign_key',
                'dtd'            	=> 'OWA_DTD_BIGINT',
                'linked_entity'		=> 'base.document'
            ),

            'last_page_id'     => array(

                'type'             	=> 'foreign_key',
                'dtd'            	=> 'OWA_DTD_BIGINT',
                'linked_entity'		=> 'base.document'
            ),

            //drop?
            'host'             => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop?
            'city'             => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop?
            'country'             => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop?
            'site'             => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_VARCHAR255'
            ),

            //drop?
            'is_robot'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            //drop?
            'is_browser'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            //drop?
            'is_feedreader'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            'latest_attributions'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BLOB'
            ),

            'num_goals'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            'num_goal_starts'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_TINYINT'
            ),

            'goals_value'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),

            // Number of commerce transactions that occurred during session
            'commerce_trans_count'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            // Revenue including tax and shipping
            'commerce_trans_revenue'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),

            // Revenue from items (excludes tax and shipping)
            'commerce_items_revenue'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),

            // Distinct number of items in all commerce transactions
            'commerce_items_count'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            // Total quantity of all items
            'commerce_items_quantity'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_INT'
            ),

            // Total shipping revenue from all transactions
            'commerce_shipping_revenue'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            ),
            // Total Tax revenue from all transactions
            'commerce_tax_revenue'     => array(

                'type'             	=> '',
                'dtd'            	=> 'OWA_DTD_BIGINT'
            )
        );

        // Add goal properties

        // determine number of goals from settings
        $gcount = owa_coreAPI::getSetting('base', 'numGoals');

        for ( $num = 1; $num <= $gcount; $num++ ) {

            $col_name = 'goal_' . $num;

            $properties[ $col_name ] = array(

                'type' 	=> '',
                'dtd'   => 'OWA_DTD_TINYINT'
            );

            $col_name = 'goal_'.$num.'_start';

            $properties[ $col_name ] = array(

                'type' 	=> '',
                'dtd'   => 'OWA_DTD_TINYINT'
            );

            $col_name = 'goal_'.$num.'_value';

            $properties[ $col_name ] = array(

                'type' 	=> '',
                'dtd'   => 'OWA_DTD_BIGINT'
            );

        }

        // return properties

        return $properties;
    }
}

?>