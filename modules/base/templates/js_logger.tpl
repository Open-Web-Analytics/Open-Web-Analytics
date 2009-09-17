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

// Create a page view logger and log the page view
OWALogger = new OWA.logger();

OWALogger.setSiteId(owa_params.site_id);
OWALogger.setEndpoint(OWA.config.log_url);
OWALogger.logPageView();
<?php if ($log_clicks === true): ?>
// Create click logger and register it to handle click events
//OWALogger.trackClicks();
<?php endif;?>

<?php if (owa_coreAPI::getSetting('base', 'log_dom_stream') === true): ?>
OWALogger.trackDomStream();
<?php endif;?>