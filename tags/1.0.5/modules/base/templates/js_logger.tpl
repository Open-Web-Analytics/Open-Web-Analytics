<? include("js_log_lib.tpl"); ?>

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

<? if ($log_pageview == true): ?>

/**
 * Logs Page View
 *
 * Takes owa_param object which is defined by the logging tag.
 *
 * @param owa_params Object
 */
var owa_pv = new OWA.pageView(owa_params);
owa_pv.log();

<? endif;?>

<? if ($is_embedded == true): ?>

	<?include('js_owa_params.tpl'); ?>

<? endif;?>

<? if ($log_clicks == true): ?>

/**
 * Logs Click
 *
 * Takes owa_param object which is defined by the logging tag.
 *
 * @param owa_params Object
 */
//Log Clicks
var owa_click = new OWA.click(owa_params);


/**
 * Helper function for setting properties o nthe cick object
 *
 * Takes a DOM event object
 *
 * @param e Object
 */
function owa_setClick(e) {

	owa_click.setProperties(e);
	return;

}

/**
 * Helper Function for calling the log method on the lick object
 *
 */
function owa_logClick() {

	owa_click.log();
	return;
}

// Registers the handler for the on.click event so that click properties can be set
document.onclick = owa_setClick;

// Registers the handler for the beforenavigate event so that the click can be logged
window.addEventListener('beforeunload', owa_logClick, false);

<? endif;?>