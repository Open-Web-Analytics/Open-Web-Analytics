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
 * 005 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3
 */


class owa_base_005_update extends owa_update {

    var $schema_version = 5;
    var $is_cli_mode_required = true;

    function up() {

        $tables = array('owa_session', 'owa_request', 'owa_click', 'owa_feed_request');

        foreach ($tables as $table) {

            // add yyyymmdd column to owa_session
            $db = owa_coreAPI::dbSingleton();
            $db->addColumn($table, 'yyyymmdd', 'INT');
            $db->addIndex($table, 'yyyymmdd');
            $ret = $db->query("update $table set yyyymmdd = 
                        concat(cast(year as CHAR), lpad(CAST(month AS CHAR), 2, '0'), lpad(CAST(day AS CHAR), 2, '0')) ");

            if ($ret == true) {
                $this->e->notice('Added yyyymmdd column to '.$table);
            } else {
                $this->e->notice('Failed to add yyyymmdd column to '.$table);
                return false;
            }
        }

        $visitor = owa_coreAPI::entityFactory('base.visitor');

        $ret = $visitor->addColumn('num_prior_sessions');

        if (!$ret) {
            $this->e->notice('Failed to add num_prior_sessions column to owa_visitor');
            return false;
        }

        $ret = $visitor->addColumn('first_session_yyyymmdd');

        if (!$ret) {
            $this->e->notice('Failed to add first_session_yyyymmdd column to owa_visitor');
            return false;
        }

        $ret = $db->query("update owa_visitor set first_session_yyyymmdd = 
                        concat(cast(first_session_year as CHAR), lpad(CAST(first_session_month AS CHAR), 2, '0'), lpad(CAST(first_session_day AS CHAR), 2, '0')) ");

        if (!$ret) {
            $this->e->notice('Failed to populate first_session_yyyymmdd column in owa_visitor');
            return false;
        }

        $request = owa_coreAPI::entityFactory('base.request');

        $ret = $request->addColumn('prior_document_id');

        if (!$ret) {
            $this->e->notice('Failed to add prior_document_id column to owa_request');
            return false;
        }

        $ret = $request->addColumn('num_prior_sessions');

        if (!$ret) {
            $this->e->notice('Failed to add num_prior_sessions column to owa_request');
            return false;
        }


        $session = owa_coreAPI::entityFactory('base.session');

        $ret = $session->addColumn('num_prior_sessions');

        if (!$ret) {
            $this->e->notice('Failed to add num_prior_sessions column to owa_session');
            return false;
        }


        $ret = $session->addColumn('is_bounce');

        if (!$ret) {
            $this->e->notice('Failed to add is_bounce column to owa_session');
            return false;
        }

        $ret = $db->query("update owa_session set is_bounce = true WHERE num_pageviews = 1");

        if (!$ret) {
            $this->e->notice('Failed to populate is_bounce column in owa_session');
            return false;
        }

        $ret = $session->addColumn('referring_search_term_id');

        if (!$ret) {
            $this->e->notice('Failed to add referring_search_term_id column in owa_session');
            return false;
        }

        $ret = $session->addColumn('days_since_prior_session');

        if (!$ret) {
            $this->e->notice('Failed to add days_since_prior_session column in owa_session');
            return false;
        }

        $ret = $db->query("update owa_session set days_since_prior_session = round(time_sinse_priorsession/(3600*24)) WHERE time_sinse_priorsession IS NOT NULL and time_sinse_priorsession > 0");

        if (!$ret) {
            $this->e->notice('Failed to populate days_since_prior_session column in owa_session');
            return false;
        }

        $ret = $session->addColumn('days_since_first_session');


        if (!$ret) {
            $this->e->notice('Failed to add days_since_first_session column in owa_session');
            return false;
        }

        $ret = $db->query("update owa_session, owa_visitor set owa_session.days_since_first_session = round((owa_session.timestamp - owa_visitor.first_session_timestamp)/(3600*24)) WHERE owa_session.visitor_id = owa_visitor.id AND owa_visitor.first_session_timestamp IS NOT NULL");

        if (!$ret) {
            $this->e->notice('Failed to populate days_since_first_session column in owa_session');
            return false;
        }

        // add api column
        $u = owa_coreAPI::entityFactory('base.user');
        $ret = $u->addColumn('api_key');

        if (!$ret) {
            $this->e->notice('Failed to add api_key column to owa_user');
            return false;
        }

        // add uri column
        $d = owa_coreAPI::entityFactory('base.document');
        $d->addColumn('uri');
        $ret = $db->query("update owa_document set uri = substring_index(SUBSTR(url FROM 1+ length(substring_index(url, '/', 3))), '#', 1) ");

        if (!$ret) {
            $this->e->notice('Failed to add uri column to owa_document');
            return false;
        }

        $a = owa_coreAPI::entityFactory('base.action_fact');
        $ret = $a->createTable();

        if ($ret === true) {
            $this->e->notice('Action fact entity table created');
        } else {
            $this->e->notice('Action fact entity table creation failed');
            return false;
        }

        $st = owa_coreAPI::entityFactory('base.search_term_dim');
        $ret = $st->createTable();

        if ($ret === true) {
            $this->e->notice('Search Term Dimension entity table created');
        } else {
            $this->e->notice('Search Term Dimension  entity table creation failed');
            return false;
        }

        // migrate search terms to new table
        $ret = $db->query(
            "INSERT INTO
                owa_search_term_dim (id, terms, term_count) 
            SELECT 
                distinct(CRC32(LOWER(query_terms))) as id, 
                query_terms as terms, 
                length(query_terms) + 1 - length(replace(query_terms,' ','')) as term_count 
            FROM 
                owa_referer
            WHERE
                query_terms != ''"
        );

        if (!$ret) {
            $this->e->notice('Failed to migrate search terms to new table.');
            return false;
        }

        //populate search term foreign key in session table
        $ret = $db->query(
            "UPDATE 
                owa_session as session, owa_referer as referer
            SET
                session.referring_search_term_id = (CRC32(LOWER(referer.query_terms))) 
            WHERE
                session.referer_id = referer.id and
                session.referer_id != 0 AND
                referer.query_terms != ''"
        );

        if (!$ret) {
            $this->e->notice('Failed to add referring_search_term_id values to owa_session');
            return false;
        }

        //populate search source in session table
        $ret = $db->query(
            "UPDATE 
                owa_session as session
            SET
                session.source = 'organic-search'
            WHERE
                session.referring_search_term_id IS NOT null"
        );

        if (!$ret) {
            $this->e->notice('Failed to populate session.source values for organic-search');
            return false;
        }

        //populate search source in session table
        $ret = $db->query(
            "UPDATE 
                owa_session as session
            SET
                session.source = 'referral'
            WHERE
                session.referer_id != 0 AND
                session.referer_id != '' AND
                session.referer_id IS NOT null AND
                session.source != 'feed' AND
                session.source != 'organic-search'"
        );

        if (!$ret) {
            $this->e->notice('Failed to populate session.source values for referral');
            return false;
        }


        // add apiKeys to each user
        $users = $db->get_results("select user_id from owa_user");

        foreach ($users as $user) {

            $u = owa_coreAPI::entityFactory('base.user');
            $u->load($user['user_id'],'user_id');

            if (!$u->get('api_key')) {
                $u->set('api_key', $u->generateTempPasskey($u->get('user_id')));
                $u->update();
            }
        }

        // change character encoding to UTF-8
        $tables = array('owa_request', 'owa_session', 'owa_feed_request', 'owa_click', 'owa_document', 'owa_ua', 'owa_site', 'owa_user', 'owa_configuration', 'owa_visitor', 'owa_os', 'owa_impression', 'owa_host', 'owa_exit','owa_domstream');

        foreach ($tables as $table) {

            // change snippet dtd
            $ret = $db->query(sprintf("ALTER TABLE %s CONVERT TO CHARACTER SET utf8", $table));

            if (!$ret) {
                $this->e->notice('Failed to change table character encoding for: ' .$table);
                return false;
            }

        }

        // change snippet dtd
        $ret = $db->query("ALTER TABLE owa_referer MODIFY snippet MEDIUMTEXT");

        if (!$ret) {
            $this->e->notice('Failed to modify snippet column of owa_referer');
            return false;
        }

        // change snippet dtd
        $ret = $db->query("ALTER TABLE owa_domstream MODIFY page_url VARCHAR(255)");

        if (!$ret) {
            $this->e->notice('Failed to modify page_url column of owa_domstream');
            return false;
        }

        // change snippet dtd
        $ret = $db->query("ALTER TABLE owa_domstream MODIFY events MEDIUMTEXT");

        if (!$ret) {
            $this->e->notice('Failed to modify events column of owa_domstream');
            return false;
        }

        // change snippet dtd
        $ret = $db->query("ALTER TABLE owa_site MODIFY description MEDIUMTEXT");

        if (!$ret) {
            $this->e->notice('Failed to modify description column of owa_site');
            return false;
        }

        // check for bad permissions on config file
        if (file_exists(OWA_DIR . 'owa-config.php')) {
            @chmod(OWA_DIR . 'owa-config.php', 0750);
        }

        if (file_exists(OWA_DIR . 'conf/owa-config.php')) {
            @chmod(OWA_DIR . 'conf/owa-config.php', 0750);
        }

        if (file_exists(OWA_DIR . 'cli.php')) {
            @chmod(OWA_DIR . 'cli.php', 0700);
        }

        // must return true
        return true;
    }

    function down() {

        $visitor = owa_coreAPI::entityFactory('base.visitor');
        $visitor->dropColumn('num_prior_sessions');
        $visitor->dropColumn('first_session_yyyymmdd');
        $session = owa_coreAPI::entityFactory('base.session');
        $session->dropColumn('yyyymmdd');
        $session->dropColumn('is_bounce');
        $session->dropColumn('referring_search_term_id');
        $session->dropColumn('days_since_first_session');
        $session->dropColumn('days_since_prior_session');
        $session->dropColumn('num_prior_sessions');
        $request = owa_coreAPI::entityFactory('base.request');
        $request->dropColumn('yyyymmdd');
        $request->dropColumn('prior_document_id');
        $request->dropColumn('num_prior_sessions');
        $click = owa_coreAPI::entityFactory('base.click');
        $click->dropColumn('yyyymmdd');
        $feed_request = owa_coreAPI::entityFactory('base.feed_request');
        $feed_request->dropColumn('yyyymmdd');
        $u = owa_coreAPI::entityFactory('base.user');
        $u->dropColumn('api_key');
        $u = owa_coreAPI::entityFactory('base.document');
        $u->dropColumn('uri');
        $af = owa_coreAPI::entityFactory('base.action_fact');
        $af->dropTable();
        $st = owa_coreAPI::entityFactory('base.search_term_dim');
        $st->dropTable();

        return true;
    }
}

?>