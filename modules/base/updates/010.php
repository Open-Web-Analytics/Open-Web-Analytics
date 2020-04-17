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
 * 010 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.6.8
 */


class owa_base_010_update extends owa_update {

    var $schema_version = 10;
    var $is_cli_mode_required = false;

    function up() {

        $domstream = owa_coreAPI::entityFactory('base.domstream');
        $domstreams_columns = [
                'page_width',
                'page_height',
        ];

        // add columns to owa_domstream
        foreach ( $domstreams_columns as $domstreams_column ) {
            $ret = $domstream->addColumn( $domstreams_column );
            if ( $ret === true ) {
                $this->e->notice( "$domstreams_column added to owa_domstream" );
            } else {
                $this->e->notice( "Adding $domstreams_column to owa_domstream failed." );
                return false;
            }
        }

        // must return true
        return true;
    }

    function down() {

        $domstream = owa_coreAPI::entityFactory('base.domstream');
        $domstreams_columns = [
            'page_width',
            'page_height',
        ];

        // Removing columns from owa_domstream
        foreach ( $domstreams_columns as $domstreams_column ) {
            $ret = $domstream->dropColumn( $domstreams_column );
            if ( $ret === true ) {
                $this->e->notice( "$domstreams_column removed from owa_domstream" );
            } else {
                $this->e->notice( "Removing $domstreams_column from owa_domstream failed." );
                return false;
            }
        }

        // must return true
        return true;
    }
}

?>