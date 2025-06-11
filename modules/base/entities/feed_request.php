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

class owa_feed_request extends owa_entity {

    function __construct() {

        $this->setTableName('feed_request');
        // properties
        $this->properties['id'] = new owa_dbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();

        $visitor_id = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
        $visitor_id->setForeignKey('base.visitor');
        $this->setProperty($visitor_id);

        $session_id = new owa_dbColumn('session_id', OWA_DTD_BIGINT);
        $session_id->setForeignKey('base.session');
        $this->setProperty($session_id);

        $document_id = new owa_dbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        $site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
        $site_id->setForeignKey('base.site', 'site_id');
        $this->setProperty($site_id);

        // wrong data type
        $ua_id = new owa_dbColumn('ua_id', OWA_DTD_VARCHAR255);
        $ua_id->setForeignKey('base.ua');
        $this->setProperty($ua_id);

        $host_id = new owa_dbColumn('host_id', OWA_DTD_BIGINT);
        $host_id->setForeignKey('base.host');
        $this->setProperty($host_id);

        // wrong data type
        $os_id = new owa_dbColumn('os_id', OWA_DTD_VARCHAR255);
        $os_id->setForeignKey('base.os');
        $this->setProperty($os_id);

        //drop
        $this->properties['site'] = new owa_dbColumn;
        $this->properties['site']->setDataType(OWA_DTD_VARCHAR255);

        //drop
        $this->properties['host'] = new owa_dbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);

        $this->properties['feed_reader_guid'] = new owa_dbColumn;
        $this->properties['feed_reader_guid']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['subscription_id'] = new owa_dbColumn;
        $this->properties['subscription_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['timestamp'] = new owa_dbColumn;
        $this->properties['timestamp']->setDataType(OWA_DTD_BIGINT);
        $yyyymmdd =  new owa_dbColumn;
        $yyyymmdd->setName('yyyymmdd');
        $yyyymmdd->setDataType(OWA_DTD_INT);
        $yyyymmdd->setIndex();
        $this->setProperty($yyyymmdd);
        $this->properties['month'] = new owa_dbColumn;
        $this->properties['month']->setDataType(OWA_DTD_INT);
        $this->properties['day'] = new owa_dbColumn;
        $this->properties['day']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['dayofweek'] = new owa_dbColumn;
        $this->properties['dayofweek']->setDataType(OWA_DTD_VARCHAR10);
        $this->properties['dayofyear'] = new owa_dbColumn;
        $this->properties['dayofyear']->setDataType(OWA_DTD_INT);
        $this->properties['weekofyear'] = new owa_dbColumn;
        $this->properties['weekofyear']->setDataType(OWA_DTD_INT);
        $this->properties['year'] = new owa_dbColumn;
        $this->properties['year']->setDataType(OWA_DTD_INT);
        $this->properties['hour'] = new owa_dbColumn;
        $this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['minute'] = new owa_dbColumn;
        $this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['second'] = new owa_dbColumn;
        $this->properties['second']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['msec'] = new owa_dbColumn;
        $this->properties['msec']->setDataType(OWA_DTD_INT);
        $this->properties['last_req'] = new owa_dbColumn;
        $this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
        $this->properties['feed_format'] = new owa_dbColumn;
        $this->properties['feed_format']->setDataType(OWA_DTD_VARCHAR255);
        //drop
        $this->properties['ip_address'] = new owa_dbColumn;
        $this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
        //drop
        $this->properties['os'] = new owa_dbColumn;
        $this->properties['os']->setDataType(OWA_DTD_VARCHAR255);

        $yyyymmdd =  new owa_dbColumn;
        $yyyymmdd->setName('yyyymmdd');
        $yyyymmdd->setDataType(OWA_DTD_INT);
        $yyyymmdd->setIndex();
        $this->setProperty($yyyymmdd);

    }



}



?>