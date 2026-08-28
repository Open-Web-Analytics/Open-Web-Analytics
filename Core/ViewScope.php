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

namespace OWA\Core;

/**
 * The explicit render scope handed to a template as `$view`.
 *
 * WHY THIS EXISTS
 * ---------------
 * Templates historically received their data as bare local variables, produced
 * by `extract($this->vars)` in TemplateEngine::fetch(), and reached the template
 * helpers through `$this` (the include happens inside that method, so `$this` is
 * in scope). That contract has two costs:
 *
 *   - A key the controller never set is simply an UNDEFINED VARIABLE. In scalar
 *     context that is a warning; in `foreach` it is a fatal ("must be of type
 *     array|object, bool given") because undefined coerces to false. The failure
 *     surfaces in the template, far from the controller that forgot the key.
 *   - Nothing declares what a template needs, so neither PHPStan nor an IDE can
 *     see the contract. Analysing the tree reported ~426 undefined template
 *     variables plus 562 `$this` false positives (templates are analysed as
 *     standalone files, where `$this` legitimately does not exist).
 *
 * `$view` replaces both: `$view->tabs` for data, `$view->out(...)` for helpers.
 * Reading a key that was never set throws instead of silently yielding false.
 *
 * SEMANTICS ARE DELIBERATELY IDENTICAL TO extract(), EXCEPT FOR THE THROW
 * ----------------------------------------------------------------------
 * __isset() uses isset() on the underlying value, so `isset($view->x)` and
 * `empty($view->x)` behave exactly as `isset($x)` / `empty($x)` did against an
 * extracted local -- false for a null value, false for a missing key, and NEVER
 * throwing. 54 isset() and 32 empty() call sites across the templates depend on
 * that. __get() uses array_key_exists(), so a key set to null returns null
 * rather than throwing; only a key that was NEVER set is an error. The result is
 * that the throw fires precisely on the case that used to be a silent fatal.
 *
 * BACKWARDS COMPATIBILITY
 * -----------------------
 * extract() REMAINS in fetch(). Third-party module templates, site-owner
 * templates/local/ overrides and custom themes are written against the bare-var
 * contract, and OWA neither ships nor can migrate them -- Template resolves
 * templates from four roots (base, module, module local, theme). Those keep
 * working untouched, and `$this` keeps working there too. Only OWA's own
 * templates use `$view`. The bare-var path is deprecated and goes away at v2.0,
 * on the same schedule as the owa_* class-name bridge.
 *
 * The @method list below is the TEMPLATE-FACING API surface -- every helper OWA's
 * own templates actually call, forwarded by __call to the Template. It is declared
 * rather than left to magic because PHPStan does not infer anything from __call:
 * without these, each of the 560 helper call sites reports an undefined method.
 * Declaring them also documents what a template is allowed to reach for.
 *
 * @method mixed choose_browser_icon($browser_type)
 * @method mixed createNonceFormField($action)
 * @method mixed displaySeriesAsSparkline($name, $result_set_obj, $id = '')
 * @method mixed escapeForXml($string)
 * @method mixed formatCurrency($value)
 * @method mixed get($name)
 * @method mixed getAvatarImage($email)
 * @method mixed getBrowserIcon($browser_family, $size = '128x128', $module = 'base')
 * @method mixed getCurrentUser()
 * @method string configFileConstantFor(string $module, string $key)
 * @method mixed getNs()
 * @method mixed getSiteThumbnail($domain, $width = '200')
 * @method mixed getTemplatePath($module, $file)
 * @method mixed getValue($key, $var)
 * @method mixed getWidget($do, $params = [], $wrapper = true, $add_state = true)
 * @method mixed headerActions()
 * @method mixed isValueSet($string)
 * @method mixed makeAbsoluteLink($params = [], $add_state = false, $url = '', $xml = false)
 * @method mixed makeApiLink($params = [], $add_state = false, $add_apiKey = false)
 * @method mixed makeImageLink($path, $absolute = false)
 * @method mixed makeJson($array)
 * @method mixed makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false)
 * @method mixed makeNavigationMenu($links, $currentSiteId, $current = '')
 * @method mixed makePagination($pagination, $map = [], $add_state = true, $template = '')
 * @method mixed makePaginationFromResultSet($pagination, $map = [], $add_state = true, $template = '')
 * @method mixed makeParamString($params = [], $add_state = false, $format = 'query', $namespace = true)
 * @method mixed makeWikiLink($page)
 * @method mixed out($output, $sanitize = true, $decode_special_entities = false)
 * @method mixed renderDimension($template, $properties)
 * @method mixed renderKpiInfobox($number, $label, $link = '', $class = '')
 * @method mixed safeHref($url, $echo = true)
 * @method mixed setTemplate($file)
 * @method mixed substituteValue($string, $var_name)
 * @method mixed truncate($str, $length=10, $trailing='...')
 */
class ViewScope {

    /**
     * @param TemplateEngine $template The template being rendered; supplies both
     *                                 the view vars and the helper methods.
     */
    public function __construct(private TemplateEngine $template) {}

    /**
     * Read a view var.
     *
     * View data ONLY -- deliberately no fallback to a property on the Template. The
     * two are different things: a template's `$this->config` is the Template's own
     * config, which is not the same as a view var that happens to be called
     * 'config'. Conflating them let a view var shadow the property and silently
     * return the wrong value. Property reads stay as `$this->` in templates.
     */
    public function __get(string $name): mixed {

        // array_key_exists, not isset: a var deliberately set to null must read
        // back as null, exactly as an extracted local would.
        if (array_key_exists($name, $this->template->vars)) {
            return $this->template->vars[$name];
        }

        throw new \OutOfBoundsException(sprintf(
            'Template "%s" read view var $view->%s, which was never set. '
            . 'Set it in the controller/view before rendering (initialize it '
            . 'unconditionally if it is only populated on some branches).',
            $this->template->file ?? 'unknown',
            $name
        ));
    }

    /**
     * isset()/empty() support. Mirrors isset() on an extracted local: false for a
     * null value and false for a missing key. Never throws -- isset() must not.
     */
    public function __isset(string $name): bool {

        return isset($this->template->vars[$name]);
    }

    /**
     * View data is read-only from inside a template. Nothing in OWA's templates
     * writes one; this turns a would-be silent dynamic-property creation into a
     * clear error.
     */
    public function __set(string $name, mixed $value): void {

        throw new \LogicException(sprintf(
            'Template "%s" tried to assign $view->%s. View data is read-only '
            . 'inside a template; set it in the controller or view instead.',
            $this->template->file ?? 'unknown',
            $name
        ));
    }

    /**
     * Template helpers -- out(), makeLink(), getNs(), makeApiLink(), getValue()
     * and friends -- forwarded to the template. `$this` inside those methods is
     * still the Template, so behavior is unchanged.
     */
    public function __call(string $name, array $arguments): mixed {

        return $this->template->$name(...$arguments);
    }

    /**
     * Escape hatch for the rare case a template needs the Template object itself.
     */
    public function owaTemplate(): TemplateEngine {

        return $this->template;
    }
}
