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
/*


        // drop
        $this->properties['user_email'] = new owa_dbColumn;
        $this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);

        // drop these soon
        $this->properties['hour'] = new owa_dbColumn;
        $this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['minute'] = new owa_dbColumn;
        $this->properties['minute']->setDataType(OWA_DTD_TINYINT2);

        $this->properties['num_pageviews'] = new owa_dbColumn;
        $this->properties['num_pageviews']->setDataType(OWA_DTD_INT);

        // drop
        $this->properties['num_comments'] = new owa_dbColumn;
        $this->properties['num_comments']->setDataType(OWA_DTD_INT);


        // how to denormalize into other fact tables?
        $is_bounce =  new owa_dbColumn;
        $is_bounce->setName('is_bounce');
        $is_bounce->setDataType(OWA_DTD_TINYINT);
        $this->setProperty($is_bounce);

        // needed?
        $this->properties['prior_session_lastreq'] = new owa_dbColumn;
        $this->properties['prior_session_lastreq']->setDataType(OWA_DTD_BIGINT);

        // needed?
        $prior_session_id = new owa_dbColumn('prior_session_id', OWA_DTD_BIGINT);
        $this->setProperty($prior_session_id);

        // drop
        $this->properties['time_sinse_priorsession'] = new owa_dbColumn;
        $this->properties['time_sinse_priorsession']->setDataType(OWA_DTD_INT);
        // drop
        $this->properties['prior_session_year'] = new owa_dbColumn;
        $this->properties['prior_session_year']->setDataType(OWA_DTD_TINYINT4);
        // drop
        $this->properties['prior_session_month'] = new owa_dbColumn;
        $this->properties['prior_session_month']->setDataType(OWA_DTD_VARCHAR255);
        // drop
        $this->properties['prior_session_day'] = new owa_dbColumn;
        $this->properties['prior_session_day']->setDataType(OWA_DTD_TINYINT2);
        // drop
        $this->properties['prior_session_dayofweek'] = new owa_dbColumn;
        $this->properties['prior_session_dayofweek']->setDataType(OWA_DTD_INT);
        // drop
        $this->properties['prior_session_hour'] = new owa_dbColumn;
        $this->properties['prior_session_hour']->setDataType(OWA_DTD_TINYINT2);
        // drop
        $this->properties['prior_session_minute'] = new owa_dbColumn;
        $this->properties['prior_session_minute']->setDataType(OWA_DTD_TINYINT2);

        // drop
        $this->properties['os'] = new owa_dbColumn;
        $this->properties['os']->setDataType(OWA_DTD_VARCHAR255);


        // wrong data type
        //@todo investigate if this columne needs to be migrated to BIGINT in older installs.
        // move to abstract
        // $os_id = new owa_dbColumn('os_id', OWA_DTD_VARCHAR255);
        // $os_id->setForeignKey('base.os');
        // $this->setProperty($os_id);

        // wrong data type
        //@todo investigate if this columne needs to be migrated to BIGINT in older installs.
        // move to abstract
        //$ua_id = new owa_dbColumn('ua_id', OWA_DTD_VARCHAR255);
        //$ua_id->setForeignKey('base.ua');
        //$this->setProperty($ua_id);


        $first_page_id = new owa_dbColumn('first_page_id', OWA_DTD_BIGINT);
        $first_page_id->setForeignKey('base.document');
        $this->setProperty($first_page_id);

        $last_page_id = new owa_dbColumn('last_page_id', OWA_DTD_BIGINT);
        $last_page_id->setForeignKey('base.document');
        $this->setProperty($last_page_id);

        // drop
        $this->properties['host'] = new owa_dbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);


        // move to abstract
        // wrong data type
        // @todo investigate if this columne needs to be migrated to BIGINT in older installs.
        //$host_id = new owa_dbColumn('host_id', OWA_DTD_VARCHAR255);
        //$host_id->setForeignKey('base.host');
        //$this->setProperty($host_id);


        //drop
        $this->properties['city'] = new owa_dbColumn;
        $this->properties['city']->setDataType(OWA_DTD_VARCHAR255);
        //drop
        $this->properties['country'] = new owa_dbColumn;
        $this->properties['country']->setDataType(OWA_DTD_VARCHAR255);
        // drop
        $this->properties['site'] = new owa_dbColumn;
        $this->properties['site']->setDataType(OWA_DTD_VARCHAR255);


        //drop
        $this->properties['is_robot'] = new owa_dbColumn;
        $this->properties['is_robot']->setDataType(OWA_DTD_TINYINT);
        //drop
        $this->properties['is_browser'] = new owa_dbColumn;
        $this->properties['is_browser']->setDataType(OWA_DTD_TINYINT);
        //drop
        $this->properties['is_feedreader'] = new owa_dbColumn;
        $this->properties['is_feedreader']->setDataType(OWA_DTD_TINYINT);

        $this->properties['latest_attributions'] = new owa_dbColumn;
        $this->properties['latest_attributions']->setDataType(OWA_DTD_BLOB);

        // create goal related columns
        $gcount = owa_coreAPI::getSetting('base', 'numGoals');
        for ($num = 1; $num <= $gcount;$num++) {
            $col_name = 'goal_'.$num;
            $goal_col = new owa_dbColumn($col_name, OWA_DTD_TINYINT);
            $this->setProperty($goal_col);
            $col_name = 'goal_'.$num.'_start';
            $goal_col = new owa_dbColumn($col_name, OWA_DTD_TINYINT);
            $this->setProperty($goal_col);
            $col_name = 'goal_'.$num.'_value';
            $goal_col = new owa_dbColumn($col_name, OWA_DTD_BIGINT);
            $this->setProperty($goal_col);
        }

        $num_goals = new owa_dbColumn('num_goals', OWA_DTD_TINYINT);
        $this->setProperty($num_goals);

        $num_goal_starts = new owa_dbColumn('num_goal_starts', OWA_DTD_TINYINT);
        $this->setProperty($num_goal_starts);

        $goals_value = new owa_dbColumn('goals_value', OWA_DTD_BIGINT);
        $this->setProperty($goals_value);

        // transaction count
        $commerce_trans_count = new owa_dbColumn('commerce_trans_count', OWA_DTD_INT);
        $this->setProperty($commerce_trans_count);
        // revenue including tax and shipping
        $commerce_trans_revenue = new owa_dbColumn('commerce_trans_revenue', OWA_DTD_BIGINT);
        $this->setProperty($commerce_trans_revenue);
        // revenue excluding tax and shipping
        $commerce_items_revenue = new owa_dbColumn('commerce_items_revenue', OWA_DTD_BIGINT);
        $this->setProperty($commerce_items_revenue);
        // distinct number of items
        $commerce_items_count = new owa_dbColumn('commerce_items_count', OWA_DTD_INT);
        $this->setProperty($commerce_items_count);
        // total quantity of all items
        $commerce_items_quantity = new owa_dbColumn('commerce_items_quantity', OWA_DTD_INT);
        $this->setProperty($commerce_items_quantity);
        // shipping revenue
        $commerce_shipping_revenue = new owa_dbColumn('commerce_shipping_revenue', OWA_DTD_BIGINT);
        $this->setProperty($commerce_shipping_revenue);
        // tax revenue
        $commerce_tax_revenue = new owa_dbColumn('commerce_tax_revenue', OWA_DTD_BIGINT);
        $this->setProperty($commerce_tax_revenue);
*/

    }


    function getEntityPropertyList() {

        $properties = array (

            'user_email'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop
            'hour'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT2
            ),

            //drop
            'minute'         => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT2
            ),

            'last_req'         => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),

            'num_pageviews'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            //drop, not being used.
            'num_comments'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),


            'is_bounce'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            'prior_session_lastreq'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),

            'prior_session_id'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),


            'time_sinse_priorsession'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            //drop?
            'prior_session_year'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT4
            ),

            //drop?
            'prior_session_month'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop
            'prior_session_day'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT2
            ),

            //drop
            'prior_session_dayofweek'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            //drop
            'prior_session_hour'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT2
            ),

            //drop
            'prior_session_minute'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT2
            ),

            //drop?
            'os'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            'first_page_id'     => array(

                'type'             => 'foreign_key',
                'dtd'            => OWA_DTD_BIGINT,
                'linked_entity'	=> 'base.document'
            ),

            'last_page_id'     => array(

                'type'             => 'foreign_key',
                'dtd'            => OWA_DTD_BIGINT,
                'linked_entity'	=> 'base.document'
            ),

            //drop?
            'host'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop?
            'city'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop?
            'country'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop?
            'site'             => array(

                'type'             => '',
                'dtd'            => OWA_DTD_VARCHAR255
            ),

            //drop?
            'is_robot'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            //drop?
            'is_browser'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            //drop?
            'is_feedreader'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            'latest_attributions'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BLOB
            ),

            'num_goals'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            'num_goal_starts'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_TINYINT
            ),

            'goals_value'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),

            // Number of commerce transactions that occured durring session
            'commerce_trans_count'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            // Revenue including tax and shipping
            'commerce_trans_revenue'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),

            // Revenue from items (excludes tax and shipping)
            'commerce_items_revenue'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),

            // Distinact numer of items in all commerce transacions
            'commerce_items_count'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            // Total quantity of all items
            'commerce_items_quantity'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_INT
            ),

            // Total shipping revenue from all transactions
            'commerce_shipping_revenue'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            ),
            // Total Tax revenue from all transactions
            'commerce_tax_revenue'     => array(

                'type'             => '',
                'dtd'            => OWA_DTD_BIGINT
            )
        );

        // Add goal properties

        // determine number of goals from settings
        $gcount = owa_coreAPI::getSetting('base', 'numGoals');

        for ( $num = 1; $num <= $gcount; $num++ ) {

            $col_name = 'goal_' . $num;

            $properties[ $col_name ] = array(

                'type' => '',
                'dtd'    => OWA_DTD_TINYINT
            );

            $col_name = 'goal_'.$num.'_start';

            $properties[ $col_name ] = array(

                'type' => '',
                'dtd'    => OWA_DTD_TINYINT
            );

            $col_name = 'goal_'.$num.'_value';

            $properties[ $col_name ] = array(

                'type' => '',
                'dtd'    => OWA_DTD_BIGINT
            );

        }

        // return properties

        return $properties;
    }
}

?>