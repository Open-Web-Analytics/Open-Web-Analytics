<?php

namespace OWA\Core;

/**
 * The explicit render scope handed to a template as `$view`.
 *
 * `$view->tabs` for data, `$view->out(...)` for helpers. Reading a key that was
 * never set throws, in the template, naming the key -- rather than yielding an
 * undefined variable far from the controller that forgot it. It is the ONLY
 * way in: fetch() extracts nothing and includes the template from a static
 * closure, so `$this` is not in scope.
 *
 * isset() AND empty() BEHAVE AS ON A LOCAL
 * ----------------------------------------
 * __isset() uses isset() on the underlying value, so `isset($view->x)` and
 * `empty($view->x)` behave exactly as `isset($x)` / `empty($x)` on a local --
 * false for a null value, false for a missing key, and NEVER
 * throwing. The guarded reads across the templates depend on that. __get() uses
 * array_key_exists(), so a key set to null returns null rather than throwing;
 * only a key that was NEVER set is an error.
 *
 * The @method list below is the TEMPLATE-FACING API surface -- every helper OWA's
 * own templates actually call, forwarded by __call to the Template. It is declared
 * rather than left to magic because PHPStan does not infer anything from __call:
 * without these, each helper call site reports an undefined method.
 * Declaring them also documents what a template is allowed to reach for.
 *
 * @method string codeBlock($code, $language = 'HTML')
 * @method mixed createNonceFormField($action)
 * @method string escape($value)
 * @method mixed escapeForXml($string)
 * @method mixed get($name)
 * @method mixed getCurrentUser()
 * @method string configFileConstantFor(string $module, string $key)
 * @method mixed getTemplatePath($module, $file)
 * @method mixed getValue($key, $var)
 * @method mixed headerActions()
 * @method mixed makeAbsoluteLink($params = [], $add_state = false, $url = '', $xml = false)
 * @method mixed makeApiLink($params = [], $add_state = false, $method = 'GET')
 * @method mixed makeImageLink($path, $absolute = false)
 * @method string|false makeImageLinkIfPresent($path, $absolute = false)
 * @method mixed makeJson($array)
 * @method mixed makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false)
 * @method string makeOverlayApiLink($params, $resourceKey)
 * @method list<RailSection> navRail($nav, $propertyId, $params = [], $accessSiteId = '', $inSettings = false)
 * @method mixed makePaginationFromResultSet($pagination, $map = [], $add_state = true, $template = '')
 * @method mixed makeParamString($params = [], $add_state = false, $format = 'query', $namespace = true)
 * @method mixed makeWikiLink($page)
 * @method mixed out($output, $sanitize = true, $decode_special_entities = false)
 * @method void outMarkup($text, ...$markup)
 * @method list<SettingsItem> profileTabs($hierarchyNav, $params = [])
 * @method mixed safeHref($url, $echo = true)
 * @method string|false scriptJson($value, $flags = 0)
 * @method mixed setTemplate($file)
 * @method list<array{heading: string, items: list<SettingsItem>}> settingsColumn($hierarchyNav, $params = [])
 * @method mixed truncate($str, $length=10, $trailing='...')
 *
 * @phpstan-import-type RailSection from Template
 * @phpstan-import-type SettingsItem from Template
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
     * two are different things: the Template's own config is not the same as a
     * view var that happens to be called 'config'. Conflating them let a view var
     * shadow the property and silently return the wrong value. A template reads a
     * property through owaTemplate(): `$view->owaTemplate()->config`.
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
     * Template helpers -- out(), makeLink(), makeApiLink(), getValue()
     * and friends -- forwarded to the template. `$this` inside those methods is
     * still the Template, so behavior is unchanged.
     *
     * @param list<mixed> $arguments as the template passed them, any type
     */
    public function __call(string $name, array $arguments): mixed {

        return $this->template->$name(...$arguments);
    }

    /**
     * The Template object itself, for the rare read of one of its properties --
     * a template has no `$this`.
     */
    public function owaTemplate(): TemplateEngine {

        return $this->template;
    }
}
