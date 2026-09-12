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
 * Feed Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class FeedRequest extends \OWA\Core\Entity\FactTable {

    function __construct() {

        $this->setTableName('feed_request');

        /*
         * This IS a fact table -- a feed request is an event, filed under a
         * yyyymmdd, referencing dimension rows. It simply did not say so, and
         * three things select fact tables by class rather than by shape:
         * the partition commands, the dimension-id conversion, and the
         * visitor_id index update. So this table was silently left out of all
         * of them: never partitioned, so retention never reached it; and its
         * document_id/ua_id/host_id/os_id were never repointed when ids were
         * re-derived to 63 bits.
         *
         * The parent columns are absorbed FIRST so that the declarations below
         * still win. Several of them disagree with the parent on purpose --
         * ua_id and os_id are VARCHAR255 here and BIGINT there -- and this
         * change is about which tables are selected, not about correcting those
         * types, which would be a data migration of its own.
         */
        $parent_columns = parent::__construct();

        foreach ( $parent_columns as $pcolumn ) {

            $this->setProperty( $pcolumn );
        }

        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();

        /*
         * visitor_id, session_id, site_id and host_id are NOT re-declared here.
         * They were, identically to FactTable's -- except that the parent's
         * carry setIndex() and these did not, so overriding them silently
         * dropped the index. owa_request has session_id and site_id indexed;
         * owa_feed_request had neither, and site_id is filtered on by
         * essentially every report query.
         *
         * What follows is only what genuinely differs from the parent.
         */
        $document_id = new \OWA\Module\Base\Classes\DbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        // wrong data type
        $ua_id = new \OWA\Module\Base\Classes\DbColumn('ua_id', OWA_DTD_VARCHAR255);
        $ua_id->setForeignKey('base.ua');
        $this->setProperty($ua_id);

        // wrong data type
        $os_id = new \OWA\Module\Base\Classes\DbColumn('os_id', OWA_DTD_VARCHAR255);
        $os_id->setForeignKey('base.os');
        $this->setProperty($os_id);

        //drop
        $this->properties['site'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site']->setDataType(OWA_DTD_VARCHAR255);

        //drop
        $this->properties['host'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);

        $this->properties['feed_reader_guid'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['feed_reader_guid']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['subscription_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['subscription_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['timestamp'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['timestamp']->setDataType(OWA_DTD_BIGINT);
        $yyyymmdd =  new \OWA\Module\Base\Classes\DbColumn;
        $yyyymmdd->setName('yyyymmdd');
        $yyyymmdd->setDataType(OWA_DTD_INT);
        $yyyymmdd->setIndex();
        $this->setProperty($yyyymmdd);
        $this->properties['month'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['month']->setDataType(OWA_DTD_INT);
        $this->properties['day'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['day']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['dayofweek'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dayofweek']->setDataType(OWA_DTD_VARCHAR10);
        $this->properties['dayofyear'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dayofyear']->setDataType(OWA_DTD_INT);
        $this->properties['weekofyear'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['weekofyear']->setDataType(OWA_DTD_INT);
        $this->properties['year'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['year']->setDataType(OWA_DTD_INT);
        $this->properties['hour'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['minute'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['second'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['second']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['msec'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['msec']->setDataType(OWA_DTD_INT);
        $this->properties['last_req'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
        $this->properties['feed_format'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['feed_format']->setDataType(OWA_DTD_VARCHAR255);
        //drop
        $this->properties['ip_address'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
        //drop
        $this->properties['os'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['os']->setDataType(OWA_DTD_VARCHAR255);

        $yyyymmdd =  new \OWA\Module\Base\Classes\DbColumn;
        $yyyymmdd->setName('yyyymmdd');
        $yyyymmdd->setDataType(OWA_DTD_INT);
        $yyyymmdd->setIndex();
        $this->setProperty($yyyymmdd);

    }



}



?>