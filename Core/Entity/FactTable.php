<?php
namespace OWA\Core\Entity;


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
 
class FactTable extends \OWA\Core\Entity {
     
     function __construct() {
         
         $columns = array();
         
         $columns['id'] = new \OWA\Module\Base\Classes\DbColumn('id', OWA_DTD_BIGINT);
        $columns['id']->setPrimaryKey();
        
        $columns['visitor_id'] = new \OWA\Module\Base\Classes\DbColumn('visitor_id', OWA_DTD_BIGINT);
        $columns['visitor_id']->setForeignKey('base.visitor');
        // Reports filter on this: a visitor-scoped request reaches
        // where('visitor_id', ...) in the REST controller. Without an index
        // that is a full scan of the fact table, which is the largest one there
        // is. session_id and site_id below have always had one.
        $columns['visitor_id']->setIndex();
    
        $columns['session_id'] = new \OWA\Module\Base\Classes\DbColumn('session_id', OWA_DTD_BIGINT);
        $columns['session_id']->setForeignKey('base.session');
        $columns['session_id']->setIndex();
        
        $columns['site_id'] = new \OWA\Module\Base\Classes\DbColumn('site_id', OWA_DTD_VARCHAR255);
        $columns['site_id']->setForeignKey('base.site', 'site_id');
        $columns['site_id']->setIndex();
        
        $columns['referer_id'] = new \OWA\Module\Base\Classes\DbColumn('referer_id', OWA_DTD_BIGINT);
        $columns['referer_id']->setForeignKey('base.referer');
        
        $columns['ua_id'] = new \OWA\Module\Base\Classes\DbColumn('ua_id', OWA_DTD_BIGINT);
        $columns['ua_id']->setForeignKey('base.ua');
        
        $columns['host_id'] = new \OWA\Module\Base\Classes\DbColumn('host_id', OWA_DTD_BIGINT);
        $columns['host_id']->setForeignKey('base.host');
    
        $columns['os_id'] = new \OWA\Module\Base\Classes\DbColumn('os_id', OWA_DTD_BIGINT);
        $columns['os_id']->setForeignKey('base.os');
        
        $columns['location_id'] = new \OWA\Module\Base\Classes\DbColumn('location_id', OWA_DTD_BIGINT);
        $columns['location_id']->setForeignKey('base.location_dim');
        
        $columns['referring_search_term_id'] = new \OWA\Module\Base\Classes\DbColumn('referring_search_term_id', OWA_DTD_BIGINT);
        $columns['referring_search_term_id']->setForeignKey('base.search_term_dim');
        
        $columns['timestamp'] = new \OWA\Module\Base\Classes\DbColumn('timestamp', OWA_DTD_INT);
    
        $columns['yyyymmdd'] = new \OWA\Module\Base\Classes\DbColumn('yyyymmdd', OWA_DTD_INT);
        $columns['yyyymmdd']->setIndex();
        
        $columns['year'] = new \OWA\Module\Base\Classes\DbColumn('year', OWA_DTD_INT);
        $columns['month'] = new \OWA\Module\Base\Classes\DbColumn('month', OWA_DTD_INT);
        $columns['day'] = new \OWA\Module\Base\Classes\DbColumn('day', OWA_DTD_TINYINT2);
        $columns['dayofweek'] = new \OWA\Module\Base\Classes\DbColumn('dayofweek', OWA_DTD_VARCHAR10);
        $columns['dayofyear'] = new \OWA\Module\Base\Classes\DbColumn('dayofyear', OWA_DTD_INT);
        $columns['weekofyear'] = new \OWA\Module\Base\Classes\DbColumn('weekofyear', OWA_DTD_INT);
        
        $columns['last_req'] = new \OWA\Module\Base\Classes\DbColumn( 'last_req', OWA_DTD_BIGINT );
        
        $columns['ip_address'] = new \OWA\Module\Base\Classes\DbColumn('ip_address', OWA_DTD_VARCHAR255);
        
        $columns['is_new_visitor'] = new \OWA\Module\Base\Classes\DbColumn('is_new_visitor', OWA_DTD_BOOLEAN);
        
        $columns['is_repeat_visitor'] = new \OWA\Module\Base\Classes\DbColumn('is_repeat_visitor', OWA_DTD_BOOLEAN);
        
        $columns['language'] = new \OWA\Module\Base\Classes\DbColumn('language', OWA_DTD_VARCHAR255);
        
        $columns['days_since_prior_session'] = new \OWA\Module\Base\Classes\DbColumn( 'days_since_prior_session', OWA_DTD_INT );
        
        $columns['days_since_first_session'] = new \OWA\Module\Base\Classes\DbColumn( 'days_since_first_session', OWA_DTD_INT );
        
        $columns['num_prior_sessions'] = new \OWA\Module\Base\Classes\DbColumn( 'num_prior_sessions', OWA_DTD_INT );
        
        $columns['medium'] = new \OWA\Module\Base\Classes\DbColumn( 'medium', OWA_DTD_VARCHAR255 );
        
        $columns['source_id'] = new \OWA\Module\Base\Classes\DbColumn( 'source_id', OWA_DTD_BIGINT );
        $columns['source_id']->setForeignKey('base.source_dim');
        
        $columns['ad_id'] = new \OWA\Module\Base\Classes\DbColumn( 'ad_id', OWA_DTD_BIGINT );
        $columns['ad_id']->setForeignKey('base.ad_dim');
        
        $columns['campaign_id'] = new \OWA\Module\Base\Classes\DbColumn( 'campaign_id', OWA_DTD_BIGINT );
        $columns['campaign_id']->setForeignKey( 'base.campaign_dim' );
        
        $columns['user_name'] = new \OWA\Module\Base\Classes\DbColumn( 'user_name', OWA_DTD_VARCHAR255 );
        
        // custom variable columns
        $cv_max = \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );
        for ($i = 1; $i <= $cv_max;$i++) {
            
            $cvar_name_col = 'cv'.$i.'_name';
            $columns[$cvar_name_col] = new \OWA\Module\Base\Classes\DbColumn( $cvar_name_col, OWA_DTD_VARCHAR255 );
            
            $cvar_value_col = 'cv'.$i.'_value';
            $columns[$cvar_value_col] = new \OWA\Module\Base\Classes\DbColumn( $cvar_value_col, OWA_DTD_VARCHAR255 );
        }
        
        return $columns;
     }
 }

?>