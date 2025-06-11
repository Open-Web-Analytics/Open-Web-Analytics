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
 * Crawl document cli Controller
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_crawlDocumentCliController extends owa_cliController
{
    /**
     * owa_crawlDocumentCliController constructor.
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
        $doc = $this->getParam('doc');

        if ($doc) {
            $this->updateDocument($doc);
        } else {
            $this->updateAllDocuments();
        }

        owa_coreAPI::notice( "Documents updated successfully." );
    }

    public function errorAction()
    {
        $this->setView('base.crawlDocumentCli');
        $this->set('msgs', $this->getParam('validation_errors'));
    }

    public function updateDocument($id)
    {
        $doc = owa_coreAPI::entityFactory('base.document');
        $doc->load($id);
        $doc->crawlDocument();
        $doc->update();
    }

    public function updateAllDocuments()
    {
        /**
         * @var owa_entity $l
         */
        $doc = owa_coreAPI::entityFactory('base.document');

        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom($doc->getTableName());
        $db->selectColumn('id');
        $db->where('url', '(none)', '!=');

        $documents = $db->getAllRows();

        if (!$documents) {
            owa_coreAPI::notice( "No document found." );
            return;
        }

        foreach ($documents as $document) {
            $this->updateDocument($document['id']);
        }
    }
}

require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Crawl document cli View
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_crawlDocumentCliView extends owa_cliView
{

}