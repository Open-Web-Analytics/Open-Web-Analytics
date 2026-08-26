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
 * Date Range Validation
 *
 * Refuses a request whose date range is not usable: one bound without the
 * other, a bound that is not a yyyymmdd date, or a start date after its end
 * date. Equal bounds are a single day and are legal.
 *
 * Unlike the other validations this one is given the whole request map rather
 * than a single value, because the rule is a relationship between three
 * parameters and not a property of any one of them. Validation::setValues()
 * already takes an arbitrary value, so no new machinery is needed.
 *
 * The rule itself lives on TimePeriod so the web and REST paths cannot drift
 * apart on what a range is, in the same way both take their period names from
 * TimePeriod::getValidPeriods().
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class DateRange extends \OWA\Core\Validation\Validation {

    function validate() {

        $error = \OWA\Module\Base\Classes\TimePeriod::getRangeError(
            (array) $this->getValues() );

        if ( $error === '' ) {

            return;
        }

        $this->hasError();

        // A caller-supplied message wins, as it does in the other validations.
        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( $error );
        }
    }
}
