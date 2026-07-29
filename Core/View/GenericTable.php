<?php
namespace OWA\Core\View;

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
 * Generic HTMl Table View
 *
 * Will produce a generic html table
 *
 */
class GenericTable extends \owa_view {

    function __construct() {

        parent::__construct();

    }

    function render($data) {

        $this->t->set_template('wrapper_blank.php');
        $this->body->set_template('generic_table.php');

        if (!empty($data['labels'])):
            $this->body->set('labels', $data['labels']);
            $this->body->set('col_count', count($data['labels']));
        else:
            $this->body->set('labels', '');
            $this->body->set('col_count', count($data['rows'][0]));
        endif;

        if (!empty($data['rows'])):
            $this->body->set('rows', $data['rows']);
            $this->body->set('row_count', count($data['rows']));
        else:
            $this->body->set('rows', '');
            $this->body->set('row_count', 0);
        endif;

        if (array_key_exists('table_class', $data)):
            $this->body->set('table_class', $data['table_class']);
        else:
            $this->body->set('table_class', 'data');
        endif;

        if (array_key_exists('header_orientation', $data)):
            $this->body->set('header_orientation', $data['header_orientation']);
        else:
            $this->body->set('header_orientation', 'col');
        endif;

        if (array_key_exists('table_footer', $data)):
            $this->body->set('table_footer', $data['table_footer']);
        else:
            $this->body->set('table_footer', '');
        endif;

        if (array_key_exists('table_caption', $data)):
            $this->body->set('table_caption', $data['table_caption']);
        else:
            $this->body->set('table_caption', '');
        endif;

        if (array_key_exists('is_sortable', $data)) {
            if ($data['is_sortable'] != true) {
                $this->body->set('sort_table_class', '');
            }
        } else {
            $this->body->set('sort_table_class', 'tablesorter');
        }

        if (array_key_exists('table_row_template', $data)):
            $this->body->set('table_row_template', $data['table_row_template']);
        else:
            ;
        endif;

        // show the no data error msg
        if (array_key_exists('show_error', $data)):
            $this->body->set('show_error', $data['show_error']);
        else:
            $this->body->set('show_error', true);
        endif;

        $this->body->set('table_id', str_replace('.', '-', $data['params']['do']).'-table');

    }
}
