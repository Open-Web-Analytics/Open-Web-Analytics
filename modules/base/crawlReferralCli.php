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

require_once(OWA_BASE_CLASS_DIR . 'cliController.php');

/**
 * Crawl referrer cli Controller
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_crawlReferralCliController extends owa_cliController
{
    /**
     * owa_crawlReferralCliController constructor.
     * @param $params
     */
    public function __construct($params)
    {
        parent::__construct($params);

        $this->setRequiredCapability('edit_settings');
    }

    /**
     *
     */
    public function action()
    {
        $ref = $this->getParam('ref');

        if ($ref) {
            $this->updateReferrer($ref);
        } else {
            $this->updateAllReferrer();
        }

        owa_coreAPI::notice( "Referer updated successfully." );
    }

    public function errorAction()
    {
        $this->setView('base.crawlReferralCli');
        $this->set('msgs', $this->getParam('validation_errors'));
    }

    public function updateReferrer($id)
    {
        $r = owa_coreAPI::entityFactory('base.referer');
        $r->load($id);
        $r->crawlReferer();
        $r->update();
    }

    public function updateAllReferrer()
    {
        /**
         * @var owa_entity $l
         */
        $ref = owa_coreAPI::entityFactory('base.referer');

        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom($ref->getTableName());
        $db->selectColumn('id');
        $db->where('url', '(none)', '!=');
        $db->where('is_searchengine', 1, '!=');

        $referrals = $db->getAllRows();

        if (!$referrals) {
            owa_coreAPI::notice( "No referrer found." );
            return;
        }

        foreach ($referrals as $referral) {
            $this->updateReferrer($referral['id']);
        }
    }
}

require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Crawl referrer cli View
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_crawlReferralCliView extends owa_cliView
{

}