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

namespace OWA\Module\Base\Entity;

/**
 * Document Entity
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Document extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('document');
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['url'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['url']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['uri'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['uri']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['page_title'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['page_title']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['page_type'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['page_type']->setDataType(OWA_DTD_VARCHAR255);
        $this->setCachable();
    }

    public function crawlDocument()
    {
        $crawler = new \OWA\Core\Http();
        $res = $crawler->getRequest($this->get('url'));

        $title = trim($crawler->extract_title());

        if ($title) {
            $this->set('page_title', \OWA\Core\Lib::utf8Encode($title));
        }
    }
}
