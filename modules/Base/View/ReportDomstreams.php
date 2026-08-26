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
 * Domstream Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */

class ReportDomstreams extends \OWA\Core\View {

    /**
     * Every value the template reads is forwarded here BY NAME.
     *
     * The body is a separate template with its own scope, so a value the
     * controller set but this method does not mention simply is not there --
     * and a missing template variable is not an error, it is an undefined that
     * reaches whatever reads it. Adding a control to the report means adding
     * its line here too.
     */
    function render() {

        $this->body->set_template('report_domstreams.php');

        $this->body->set('domstreams', $this->get('domstreams'));
        $this->body->set('domstreams_pagination', $this->get('domstreams_pagination'));
        $this->body->set('domstreams_total', $this->get('domstreams_total'));

        // The segment filter, and why it selected nobody when it did.
        $this->body->set('domstreams_filter_dimensions', $this->get('domstreams_filter_dimensions'));
        $this->body->set('domstreams_filter_metrics', $this->get('domstreams_filter_metrics'));
        $this->body->set('domstreams_constraints', $this->get('domstreams_constraints'));
        $this->body->set('domstreams_segment_error', $this->get('domstreams_segment_error'));

        $doc = $this->get('document');
        $this->body->set('document', $doc);
        $this->body->set('properties', $this->get('item_properties'));
    }

}
