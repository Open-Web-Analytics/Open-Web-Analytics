<?php
namespace OWA\Core\Validation;

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

/**
 * Compare Range Validation
 *
 * Refuses a request whose comparison is not usable: a `compare` value that is
 * not one of TimePeriod::getValidComparePeriods(), or compare dates that do not
 * make a date range. Given the whole request map, like DateRange, and asks
 * TimePeriod so the web and REST paths share one rule.
 *
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class CompareRange extends \OWA\Core\Validation\Validation {

    function validate() {

        $error = \OWA\Module\Base\Classes\TimePeriod::getCompareError(
            (array) $this->getValues() );

        if ( $error === '' ) {

            return;
        }

        $this->hasError();

        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( $error );
        }
    }
}
