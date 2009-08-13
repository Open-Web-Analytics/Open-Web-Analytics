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

<?=$js_includes;?>


// config values
<?php $this->includeTemplate('config_dom.tpl');?>


// Create page view logger and log page view
// Takes owa_param object which is defined by the logging tag.
var owa_pv = new OWA.pageView(owa_params);
owa_pv.log();

<?php if ($log_clicks === true): ?>
// Create click logger and bind it to click events
var owa_click = new OWA.click(owa_params);

// Registers the handler for the on.click event so that click properties can be set
document.onclick = owa_setClick;

// Registers the handler for the beforenavigate event so that the click can be logged

if(window.addEventListener) {

window.addEventListener('beforeunload', owa_logClick, false);

} else if(window.attachEvent) {

window.attachEvent('beforeunload', owa_logClick);

}
<?php endif;?>