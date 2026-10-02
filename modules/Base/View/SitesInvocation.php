<?php
namespace OWA\Module\Base\View;

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
 * Sites Invocation Instructions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class SitesInvocation extends \OWA\Core\View {

    function render($data) {

        $site = $this->get('site');

        if ($site->get('name')) {
            $name = sprintf("%s (%s)", $site->get('domain'), $site->get('name'));
        } else {
            $name = $site->get('domain');
        }


        //page title
        $this->t->set('page_title', 'Tracking Tags');
        $this->body->set('site', $site);
        $this->body->set('name', $name);
        $this->body->set('options', array());
        // load body template
        $this->body->set_template('sites_invocation.php');

        $this->body->set('site_id', $this->get('site_id'));

        $this->body->set('tracking_code', \OWA\Core\CoreAPI::getJsTrackerTag( $this->get('site_id') ) );

        // False when the Profile has received nothing; the template says so.
        $this->body->set( 'last_event', $this->get( 'last_event' ) ?: 0 );

        // The Profile's tracking bundle (PLAN 2.24): where it is served, whether
        // the file is current, and the last read-back of its cache header.
        $bundle = '\OWA\Module\Base\Classes\TrackerBundle';
        $this->body->set( 'bundle_url', $bundle::url( $this->get( 'site_id' ) ) );
        $this->body->set( 'bundle_status', $bundle::status( $this->get( 'site_id' ) ) );
        $this->body->set( 'bundle_cache', (array) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cache_headers' ) );
    }
}
