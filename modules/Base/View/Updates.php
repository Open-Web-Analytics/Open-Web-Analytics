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
 * Update View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Updates extends \OWA\Core\View {
        
    function render($data) {
        
        // The signed-out wrapper unless OWA is embedded: this view may be
        // rendered before anything else. It tested isset() on a setting with no
        // default, which is never set, so the page always took the signed-in
        // wrapper -- its header without the stylesheet that lays the header out.
        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'is_embedded' ) ) {
            $this->t->set_template('wrapper_public.php');
        }
        
        $this->body->set_template('updates.php');// This is the inner template
        $this->body->set('headline', 'Your database needs to be upgraded...');
        $this->body->set('modules', $data['modules']);
    }
}
