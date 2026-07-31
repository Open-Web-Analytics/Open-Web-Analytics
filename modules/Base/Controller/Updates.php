<?php
namespace OWA\Module\Base\Controller;


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





class Updates extends \OWA\Core\Controller {

    // DELIBERATELY has no required capability. Core\Controller::updateAction()
    // redirects here (setRedirectAction('base.updates')) whenever an admin
    // request is intercepted because the schema is out of date -- and that
    // interception runs BEFORE the capability check, for a request that may not
    // be authenticated yet. Gating this controller would put a login wall in
    // front of the very page the interception exists to display.
    //
    // It only lists which modules have pending updates. base.updatesApply, the
    // action that actually mutates the schema, is gated instead.

    function action() {
        
        $data = array();
                
        $data['view_method'] = 'delegate';
        $data['view'] = 'base.updates';
        $data['modules'] = \OWA\Core\CoreAPI::getModulesNeedingUpdates();
        
        return $data;
    }
}

?>
