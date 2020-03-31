<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2011 Peter Adams. All rights reserved.
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
 * Abstract Fact Table Entity Class
 *
 * All fact tables are derived from this class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.5.0
 */
 
class owa_factTable extends owa_entity {
     
     function __construct() {
         
         $columns = array();
         
         $columns['id'] = new owa_dbColumn('id', OWA_DTD_BIGINT);
        $columns['id']->setPrimaryKey();
        
        $columns['visitor_id'] = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
        $columns['visitor_id']->setForeignKey('base.visitor');
    
        $columns['session_id'] = new owa_dbColumn('session_id', OWA_DTD_BIGINT);
        $columns['session_id']->setForeignKey('base.session');
        $columns['session_id']->setIndex();
        
        $columns['site_id'] = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
        $columns['site_id']->setForeignKey('base.site', 'site_id');
        $columns['site_id']->setIndex();
        
        $columns['referer_id'] = new owa_dbColumn('referer_id', OWA_DTD_BIGINT);
        $columns['referer_id']->setForeignKey('base.referer');
        
        $columns['ua_id'] = new owa_dbColumn('ua_id', OWA_DTD_BIGINT);
        $columns['ua_id']->setForeignKey('base.ua');
        
        $columns['host_id'] = new owa_dbColumn('host_id', OWA_DTD_BIGINT);
        $columns['host_id']->setForeignKey('base.host');
    
        $columns['os_id'] = new owa_dbColumn('os_id', OWA_DTD_BIGINT);
        $columns['os_id']->setForeignKey('base.os');
        
        $columns['location_id'] = new owa_dbColumn('location_id', OWA_DTD_BIGINT);
        $columns['location_id']->setForeignKey('base.location_dim');
        
        $columns['referring_search_term_id'] = new owa_dbColumn('referring_search_term_id', OWA_DTD_BIGINT);
        $columns['referring_search_term_id']->setForeignKey('base.search_term_dim');
        
        $columns['timestamp'] = new owa_dbColumn('timestamp', OWA_DTD_INT);
    
        $columns['yyyymmdd'] = new owa_dbColumn('yyyymmdd', OWA_DTD_INT);
        $columns['yyyymmdd']->setIndex();
        
        $columns['year'] = new owa_dbColumn('year', OWA_DTD_INT);
        $columns['month'] = new owa_dbColumn('month', OWA_DTD_INT);
        $columns['day'] = new owa_dbColumn('day', OWA_DTD_TINYINT2);
        $columns['dayofweek'] = new owa_dbColumn('dayofweek', OWA_DTD_VARCHAR10);
        $columns['dayofyear'] = new owa_dbColumn('dayofyear', OWA_DTD_INT);
        $columns['weekofyear'] = new owa_dbColumn('weekofyear', OWA_DTD_INT);
        
        $columns['last_req'] = new owa_dbColumn( 'last_req', OWA_DTD_BIGINT );
        
        $columns['ip_address'] = new owa_dbColumn('ip_address', OWA_DTD_VARCHAR255);
        
        $columns['is_new_visitor'] = new owa_dbColumn('is_new_visitor', OWA_DTD_BOOLEAN);
        
        $columns['is_repeat_visitor'] = new owa_dbColumn('is_repeat_visitor', OWA_DTD_BOOLEAN);
        
        $columns['language'] = new owa_dbColumn('language', OWA_DTD_VARCHAR255);
        
        $columns['days_since_prior_session'] = new owa_dbColumn( 'days_since_prior_session', OWA_DTD_INT );
        
        $columns['days_since_first_session'] = new owa_dbColumn( 'days_since_first_session', OWA_DTD_INT );
        
        $columns['num_prior_sessions'] = new owa_dbColumn( 'num_prior_sessions', OWA_DTD_INT );
        
        $columns['medium'] = new owa_dbColumn( 'medium', OWA_DTD_VARCHAR255 );
        
        $columns['source_id'] = new owa_dbColumn( 'source_id', OWA_DTD_BIGINT );
        $columns['source_id']->setForeignKey('base.source_dim');
        
        $columns['ad_id'] = new owa_dbColumn( 'ad_id', OWA_DTD_BIGINT );
        $columns['ad_id']->setForeignKey('base.ad_dim');
        
        $columns['campaign_id'] = new owa_dbColumn( 'campaign_id', OWA_DTD_BIGINT );
        $columns['campaign_id']->setForeignKey( 'base.campaign_dim' );
        
        $columns['user_name'] = new owa_dbColumn( 'user_name', OWA_DTD_VARCHAR255 );
        
        // custom variable columns
        $cv_max = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
        for ($i = 1; $i <= $cv_max;$i++) {
            
            $cvar_name_col = 'cv'.$i.'_name';
            $columns[$cvar_name_col] = new owa_dbColumn( $cvar_name_col, OWA_DTD_VARCHAR255 );
            
            $cvar_value_col = 'cv'.$i.'_value';
            $columns[$cvar_value_col] = new owa_dbColumn( $cvar_value_col, OWA_DTD_VARCHAR255 );
        }
        
        return $columns;
     }
 }

?>