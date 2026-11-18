<?php
namespace OWA\Core;

/**
 * OWA Wrapper for template class
 * 
 *
 * @phpstan-type LinkParams array<string, scalar|null>
 * @phpstan-type CallerParams array{module?: string, link_state?: array<string, mixed>, keep_state?: bool|list<string>, view?: string, subview?: string}
 * @phpstan-type RailItem array{label: string, href: string, current: bool, heading?: string, icon?: string}
 * @phpstan-type RailSection array{id: string, label: string, icon: string, href: string, current: bool, items: list<RailItem>, foot: bool}
 * @phpstan-type SettingsEntry array{label: string, do: string, params?: array<string, scalar|null>, capability?: string, current_for?: list<string>}
 * @phpstan-type SettingsItem array{label: string, href: string, current: bool}
 */

class Template extends TemplateEngine {

    /**
     * Configuration
     *
     * @var array<string, mixed> the base module's settings, each of its own type
     */
    public $config;

    /** @var string */
    public $module_local_template_dir;

    /** @var string */
    public $module_template_dir;

    /** @var \OWA\Module\Base\Classes\Error */
    public $e;

    /** @var mixed nothing in OWA sets or reads it */
    public $period;

    /** @var array<string, string|int> Lib::time_now() when the template was made */
    public $time_now;

    /**
     * Params passed by calling caller
     *
     * link_state is the request's params as they arrived (View::_setLinkState()).
     *
     * @var CallerParams
     */
    public $caller_params;

    /**
     * @param string|null  $module        the module whose templates are looked up, read only
     *                                    when $caller_params names one; else base
     * @param CallerParams $caller_params
     */
    public function __construct( $module = null, $caller_params = [] ) {

        $this->caller_params = $caller_params;

        $c = \OWA\Core\CoreAPI::configSingleton();
        $this->config = $c->fetch('base');

        $this->e = \OWA\Core\CoreAPI::errorSingleton();

        // set template dirs
        if(!empty($caller_params['module'])):
            $this->_setTemplateDir($module);
        else:
            $this->_setTemplateDir('base');
        endif;

        $this->time_now = \OWA\Core\Lib::time_now();
    }

    /**
     * @param  string|null $module
     * @return void
     */
    public function _setTemplateDir($module) {

        // set module template dir (on-disk module dir is PascalCase; PSR-4)
        $this->module_template_dir = \OWA\Core\Env::modulesDir() . \OWA\Core\Lib::moduleDirName( $module ) . '/templates/';

        // set module local template override dir
        $this->module_local_template_dir = $this->module_template_dir.'local'.'/';

        return;
    }

    /**
     * @param  string      $module
     * @param  string|null $file
     * @return string|false the template's full path; false when there is none
     */
    public function getTemplatePath($module, $file) {

        $this->_setTemplateDir($module);

        if ($file == null) {
            \OWA\Core\CoreAPI::error('No template file was specified.');
            return false;
        } else {
            // check module's local modification template Directory
            if (file_exists($this->module_local_template_dir.$file)) {
                $fullfile = $this->module_local_template_dir.$file;

            // check module's template directory
            } elseif(file_exists($this->module_template_dir.$file)) {
                $fullfile = $this->module_template_dir.$file;

            // throw error
            } else {
                \OWA\Core\CoreAPI::error(sprintf('%s was not found in any template directory.', $file));
                return false;
            }
            return $fullfile;
        }



    }

    /**
     * Set the template file
     * @depricated
     * @param  string|null $file
     * @return bool whether the file was found
     */
    public function set_template($file = null) {

        if (!$file):
            \OWA\Core\CoreAPI::error('No template file was specified.');
            return false;
        else:
            // Normalize the requested filename before it is used in any
            // filesystem lookup or logged in an error message. Template
            // names may only contain a safe basename character set; a
            // path-containing or exotic value would be attacker-controlled
            // config (see report_wrapper RCE chain).
            $file = self::sanitizeTemplateName( $file );

            if ( $file === '' ) {
                \OWA\Core\CoreAPI::error('Invalid template file name.');
                return false;
            }

            // check module's local modification template Directory
            if (file_exists($this->module_local_template_dir.$file)):
                $this->file = $this->module_local_template_dir.$file;

            // check module's template directory
            elseif(file_exists($this->module_template_dir.$file)):
                $this->file = $this->module_template_dir.$file;

            // throw error
            else:
                \OWA\Core\CoreAPI::error(sprintf('%s was not found in any template directory.', $file));
                return false;
            endif;

            return true;
        endif;
    }

    /**
     * Reduce a template name to a safe basename. Strips any path component
     * and any character outside [A-Za-z0-9._-]. This neutralizes payloads
     * that rely on quoted-printable escapes, angle brackets, question marks,
     * or backticks surviving into an error-log line.
     *
     * @param  mixed  $file a template name as configured: anything but a string is ''
     * @return string
     */
    public static function sanitizeTemplateName( $file ) {

        if ( ! is_string( $file ) ) {
            return '';
        }

        $file = basename( $file );
        $file = preg_replace( '/[^A-Za-z0-9._\-]/', '', $file );

        return (string) $file;
    }

    /**
     * @param  string $module
     * @param  string $file
     * @return void
     */
    public function setTemplateFile($module, $file) {

        //choose file
        $filepath = $this->getTemplatePath($module, $file);
        //set template
        if ($filepath) {
            $this->file = $filepath;
        }
    }

    /**
     * Truncate string
     *
     * @param string $str
     * @param integer $length
     * @param string $trailing
     * @return string
     */
    public function truncate ($str, $length=10, $trailing='...')  {

      return \OWA\Core\Lib::truncate ($str, $length, $trailing);
    }





    
    /**
     * Makes navigation links by checking whether or not the view
     * that is rendering the template is not the view being refered to in the link.
     *
     * @param array navigation array
     */
    /**
     * The link parameters a navigation entry points at.
     *
     * A `ref` was always a bare action name, which stopped being enough when
     * every report started sharing one: `base.report` identifies the
     * dispatcher, not the report. A ref may now be the whole parameter map
     * instead -- array('do' => 'base.report', 'reportId' => 'pages') -- and a
     * plain string still means what it always meant.
     *
     * @param array<string, mixed> $link a nav link struct: ref, anchortext, priviledge, ...
     * @return array<string, mixed> parameters for makeLink(): a ref given as a map is returned as given
     */
    public function navLinkParams( $link ) {

        $ref = isset( $link['ref'] ) ? $link['ref'] : '';

        return is_array( $ref ) ? $ref : [ 'do' => $ref ];
    }

    /**
     * Whether a navigation entry is the page being looked at.
     *
     * Comparing `ref` against params['do'] used to be enough. It is not any
     * more: every report answers to do=base.report, so that test now says yes
     * to EVERY report link at once and the whole Reports menu highlights.
     *
     * Every parameter the ref names has to match, which for a report means the
     * reportId as well as the action.
     *
     * @param array<string, mixed> $link a nav link struct: ref, anchortext, priviledge, ...
     * @param array<string, mixed> $params the current request parameters
     * @return bool
     */
    public function navLinkIsCurrent( $link, $params ) {

        $wanted = $this->navLinkParams( $link );

        if ( empty( $wanted['do'] ) ) {

            return false;
        }

        foreach ( $wanted as $key => $value ) {

            if ( ! isset( $params[ $key ] ) || (string) $params[ $key ] !== (string) $value ) {

                return false;
            }
        }

        return true;
    }





    /**
     * @param  string $page
     * @return string
     */
    public function makeWikiLink( $page ) {

        return sprintf( '%s/%s', $this->config['wiki_url'], $page );
    }

    /**
     * Returns Namespace value to template
     *
     * @return string
     */
    /**
     * The config-file constant governing a setting, or '' if none is.
     *
     * A settings field whose value comes from owa-config.php must render
     * read-only and say which constant is responsible -- a constant beats the
     * stored value (see Settings::stripSettingsSuppliedByConstants), so an
     * editable field would accept a change that never takes effect. Naming the
     * constant is what makes that actionable: "set in owa-config.php" leaves the
     * operator hunting.
     *
     * @param string $module
     * @param string $key
     * @return string
     */
    public function configFileConstantFor( $module, $key ) {

        return \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( $module, $key );
    }

    /**
     * @param  LinkParams $params
     * @param  bool       $add_state
     * @param  string     $format    query, cookie or json
     * @param  bool       $namespace not read
     * @return string|false false when json_encode() fails
     */
    public function makeParamString($params = [], $add_state = false, $format = 'query', $namespace = true) {

        $all_params = [];

        // merge in state params
        if ($add_state) {
            $all_params = array_merge($all_params, $this->getAllStateParams());
        }
        //merge in params
        $all_params = array_merge($all_params, $params);

        $string = '';

        switch($format) {

            case 'query':

                $get = '';

                $count = count($all_params);

                $i = 0;

                foreach ($all_params as $n => $v) {

                    $get .= $n.'='.$v;

                    $i++;

                    if ($i < $count):
                        $get .= "&";
                    endif;
                }

                $string= $get;

                break;

            case 'cookie':

                $string = \OWA\Core\Lib::implode_assoc('=>', '|||', $all_params);
                break;

            case 'json':

                $string = json_encode( $all_params );

                break;
        }


        return $string;

    }

    /**
     * The link state, then the period's properties when a timePeriod is set.
     *
     * @return array<string, mixed> name => value, the link state as the request carried it
     */
    public function getAllStateParams() {

        $all_params = [];

        if (!empty($this->caller_params['link_state'])) {
            $all_params = array_merge($all_params, $this->caller_params['link_state']);
        }

        // add in period properties if available
        $period = $this->get('timePeriod');

        if (!empty($period)) {
            $all_params = array_merge($all_params, $period->getPeriodProperties());
        }

        return $all_params;
    }
    


    /**
     * Makes Links, adds state to links optionaly.
     *
     * @param array $params
     * On a screen that keeps state in its links (View::keepStateInLinks()),
     * a link asking for none gets those keys.
     *
     * @param LinkParams $params
     * @param bool|list<string> $add_state true for all of the link state, or the
     *                                    keys of it to add (array( 'propertyId' ))
     * @param string $url  the base URL; main_url when empty
     * @param bool   $xml  whether to escape the link for XML
     * @param bool   $add_nonce
     * @return string
     */
    public function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

        $all_params = [];

        if ( $add_state === false && ! empty( $this->caller_params['keep_state'] ) ) {

            $add_state = $this->caller_params['keep_state'];
        }

        //Loads link state passed by caller
        if ($add_state) {

            $state = $this->getAllStateParams();

            if (is_array($add_state)) {
                $state = array_intersect_key($state, array_flip($add_state));
            }

            $all_params = array_merge($all_params, $state);
        }

        // Load overrides
        if (!empty($params)) {
            $params = array_filter($params);
            $all_params = array_merge($all_params, $params);
        }

        // add nonce if called for
        if ($add_nonce) {
            $action = '';
            if ( array_key_exists('do', $all_params) ) {
                $action = $all_params['do'];
            } elseif ( array_key_exists('action', $all_params) ) {
                $action = $all_params['action'];
            }

            $all_params['nonce'] = \OWA\Core\CoreAPI::createNonce($action);
        }

        $get = '';

        if (!empty($all_params)):

            $count = count($all_params);

            $i = 0;

            foreach ($all_params as $n => $v) {

                $get .= \OWA\Module\Base\Classes\Sanitize::escapeForDisplay($n).'='.\OWA\Module\Base\Classes\Sanitize::escapeForDisplay($v);

                $i++;

                if ($i < $count):
                    $get .= "&";
                endif;
            }
        endif;

        if (empty($url)):
            $url = $this->config['main_url'];
        endif;

        $link = sprintf($this->config['link_template'], $url, $get);

        if ($xml == true):
            $link = $this->escapeForXml($link);
        endif;

        return $link;

    }

    /**
     * @param  string $string
     * @return string
     */
    public function escapeForXml($string) {

        $string = str_replace(['&', '"', "'", '<', '>' ], ['&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'], $string);
        // removes non-ascii chars
        $string = \OWA\Core\Lib::escapeNonAsciiChars($string);
        return $string;
    }

    /**
     * @param  LinkParams        $params
     * @param  bool|list<string> $add_state
     * @param  string            $url  the base URL; main_absolute_url when empty
     * @param  bool              $xml
     * @return string
     */
    public function makeAbsoluteLink($params = [], $add_state = false, $url = '', $xml = false) {

        if (empty($url)):
            $url = $this->config['main_absolute_url'];
        endif;

        return $this->makeLink($params, $add_state, $url, $xml);

    }
    
    /**
     * An API link for an overlay: the heatmap, or a module's.
     *
     * These two are the only cross-origin API consumers: they run on the
     * *tracked* site and call back to the OWA origin. They used to be built
     * with makeApiLink( ..., $add_apiKey = true ), which put the signed-in
     * user's long-lived apiKey into a URL that the tracker then wrote to a
     * cookie on the tracked site's own domain -- readable by every other script
     * on that page, and re-sent to that site on every subsequent request.
     *
     * They now carry a token scoped to one endpoint and one resource that
     * expires in minutes, so what leaks is worth almost nothing. No signature:
     * request signing exists to make a long-lived key survivable in a URL, and
     * there is no longer a long-lived key here.
     *
     * **The full API URL must be built here and handed to the overlay. Do not
     * be tempted to send only the token and let the tracker derive the URL.**
     *
     * It looks redundant -- the token already names its action and resource,
     * and the tracker has a base URL -- but the tracker's base URL is where it
     * *logs*, which need not be where reporting lives. OWA supports split
     * deployment deliberately: setEndpoint(), setLoggerEndpoint() and
     * setApiEndpoint() are independently settable, and OWA_SERVER_ROLE splits
     * an install across servers: a collector receives beacons and queues them
     * on SQS for a worker to drain, and never touches the reporting database.
     * The admin interface can be on an entirely different domain from the
     * collector.
     *
     * The reporting origin is the only party that knows where reporting lives,
     * and it is already the party minting this link, so the API URL and the
     * token both travel from here. Deriving it client-side works on a
     * single-box install and fails silently on exactly the deployments that
     * most need it right, by fetching from a host that never answers.
     *
     * **The token holds the resource as the API will READ it back**, which is
     * the parameter decoded once: the link carries the value as given, and PHP
     * decodes the query string. The heatmap encodes its page path into
     * `constraints` (pagePath==%2Fpricing), the request arrived as
     * pagePath==/pricing, and no token ever matched -- every heatmap's data
     * was refused with a 401 and the overlay drew nothing.
     *
     * @param    LinkParams $params      the API request params
     * @param    string    $resourceKey    which param names the resource
     * @return    string
     */
    public function makeOverlayApiLink( $params, $resourceKey ) {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        parse_str( 'resource=' . (string) $params[ $resourceKey ], $as_read );

        // What the link will carry for each scoped key: its own value, else
        // the state makeLink() adds below.
        $state = $this->getAllStateParams();
        $scope = [];

        foreach ( \OWA\Core\OverlayToken::SCOPE as $key ) {

            $scope[ $key ] = (string) ( $params[ $key ] ?? $state[ $key ] ?? '' );
        }

        $params['overlayToken'] = \OWA\Core\OverlayToken::mint(
            $cu->getUserData('user_id'),
            $params['do'],
            $resourceKey,
            (string) ( $as_read['resource'] ?? '' ),
            \OWA\Core\OverlayToken::DEFAULT_TTL,
            $scope
        );

        // add_state MUST be true. It is what carries siteId and the reporting
        // period onto the link, and both overlay call sites used
        // makeApiLink( $params, true, true ) before this method existed.
        //
        // Dropping it fails asymmetrically, which is why it is worth a comment:
        // the player's controller declares siteId required and answers a clean
        // 422, but the heatmap's reports route does not, so it returns 200 for a
        // query with no site filter and a defaulted period -- the wrong clicks,
        // reported as success. Only the cross-origin e2e caught that half.
        return $this->makeLink( $params, true, $this->config['rest_api_url'] );
    }

    /**
     * A REST link with a nonce for the HTTP method the page sends it with.
     *
     * @param LinkParams        $params    version, module and do, and the request's own
     * @param bool|list<string> $add_state
     * @param string            $method    GET, POST, DELETE, ...
     * @return string
     */
    public function makeApiLink($params = [], $add_state = false, $method = 'GET') {

        $url = $this->config['rest_api_url'];

        $params['nonce'] = \OWA\Core\CoreAPI::createRestApiNonce( $params['version'], $params['module'], $params['do'], $method );

        return $this->makeLink($params, $add_state, $url);
    }

    /**
     * @param  string $path     under the public/ asset tree
     * @param  bool   $absolute not read: the assets URL is absolute
     * @return string
     */
    public function makeImageLink($path, $absolute = false) {

        // Server-rendered images live in the public/ asset tree (the build copies
        // base/i/ -> public/base/i/), NOT the module source tree -- modules_url is
        // denied by the deny-all .htaccess. Absolute or not, it is the assets
        // URL (Settings::assetsUrl(), OWA_ASSETS_URL), which is absolute.
        return \OWA\Core\CoreAPI::configSingleton()->assetsUrl().$path;

    }

    /**
     * Whether the webpack build has produced the public/ asset tree.
     *
     * public/ is gitignored and built by 'npm run build', so a source checkout
     * -- or the GitHub "Source code" archive rather than the packaged release
     * tarball -- has none of it. Every makeImageLink() target then 404s, which
     * is how an unbuilt install shows up on screen: a broken logo on every page
     * including the installer's own.
     *
     * The same directory the installer's environment check tests, deliberately:
     * modules/Base/Controller/InstallCheckEnv.php reads this method rather than
     * repeating the path, so "built" cannot come to mean two different things.
     *
     * @return bool
     */
    public static function assetsAreBuilt() {

        static $built = null;

        if ( $built === null ) {

            $built = is_dir( \OWA\Core\Env::root() . '/public/base/dist' );
        }

        return $built;
    }

    /**
     * makeImageLink(), but only when the file is actually there.
     *
     * Answers false instead of a URL so a template can fall back to text rather
     * than emit a broken <img>. Checks the build first because that is the
     * common cause and costs one cached stat; the per-file test then also
     * catches a logo_image_path pointing at something that was never there.
     *
     * @param string $path
     * @param bool $absolute
     * @return string|false
     */
    public function makeImageLinkIfPresent( $path, $absolute = false ) {

        if ( ! $path || ! self::assetsAreBuilt() ) {

            return false;
        }

        $public_path = \OWA\Core\CoreAPI::getSetting( 'base', 'public_path' );

        if ( ! $public_path ) {

            $public_path = \OWA\Core\Env::root() . '/public/';
        }

        $file = rtrim( $public_path, '/' ) . '/' . ltrim( $path, '/' );

        if ( ! is_readable( $file ) ) {

            return false;
        }

        return $this->makeImageLink( $path, $absolute );
    }

    /**
     * @param  string      $file
     * @return string|null the template's full path; the one set before when $file is not found
     */
    public function setTemplate($file) {

        $this->set_template($file);
        return $this->file;

    }


    /**
     * A JS object literal, for embedding in a <script> block.
     *
     * report.php assigns the result to OWA.items[...].properties, and its
     * input is $view->params, which is URL-controlled. report_widgets.php reads
     * it back into a widget's description, as the values a grid's column link
     * is given (owa.reportWidgets.js passes them to addLinkToColumn()).
     *
     * It used to be assembled by string concatenation and escaped with
     * Sanitize::escapeForDisplay(), which is htmlentities(): an HTML escape
     * applied to a JavaScript context. HTML escaping answers the wrong question
     * here. It leaves a backslash untouched, and a backslash is precisely what a
     * JS string literal treats as an escape, so the closing quote a value was
     * meant to sit inside is not reliably the one that ends it.
     *
     * json_encode() is the right tool and needs no reasoning about which
     * characters matter. The HEX flags additionally emit <, >, &, ' and " as
     * \uXXXX, which is what makes the result safe to embed inline -- '</script>'
     * inside a value cannot close the block.
     *
     * Three behaviours are preserved deliberately:
     *
     *   - values are strings, as they have always been. Letting json_encode
     *     infer types would hand the JS a number or a bool where it has always
     *     had a string.
     *   - entities are decoded first. Some values are stored HTML-encoded, and
     *     the true value is what the JS should receive; escapeForDisplay() did
     *     this too, by decoding before re-encoding.
     *   - FORCE_OBJECT, because one caller passes a list. (array) on its
     *     valueColumns yields integer keys, which emitted `0: "x"` before and
     *     must stay an object rather than becoming a JS array.
     *
     * One bug goes with it: an empty array used to return '}' on its own, since
     * substr('{', 0, -2) is ''. It now returns '{}'.
     *
     * @param  array<mixed> $array values of any type: an object by its
     *                             toString(), an array as 'Array'
     * @return string|false false when json_encode() fails
     */
    public function makeJson($array) {

        $reserved_words = \OWA\Core\CoreAPI::getSetting('base', 'reserved_words');

        $properties = [];

        foreach ( (array) $array as $k => $v ) {

            if ( is_object( $v ) ) {

                $v = method_exists( $v, 'toString' ) ? $v->toString() : '';
            }

            if ( is_array( $v ) ) {

                // Stringified before this change too, via sprintf's %s. Kept
                // rather than serialised, because a caller that has always been
                // handed the word "Array" should not silently start receiving a
                // structure.
                $v = 'Array';
            }

            if ( array_key_exists( $k, (array) $reserved_words ) ) {

                $k = $reserved_words[ $k ];
            }

            $properties[ $k ] = html_entity_decode( (string) $v, ENT_QUOTES );
        }

        return json_encode( $properties,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT );
    }

    /**
     * @return void
     */
    public function headerActions() {

        return;
    }



    /**
     * @param  object{page: int, total_pages: int} $pagination
     * @param  LinkParams        $map       each page's link params, page aside
     * @param  bool|list<string> $add_state
     * @param  string            $template  not read
     * @return string HTML; '' for one page
     */
    public function makePaginationFromResultSet($pagination, $map = [], $add_state = true, $template = '') {

        $pages = '';

        if ($pagination->total_pages > 1) {

            $pages = '<div class="owa_pagination"><UL>';

            for ($i = 1; $i <= $pagination->total_pages;$i++) {

                if ($pagination->page != $i) {

                    $new_map = [];

                    if (is_array($map)) {
                        $new_map = array_merge($map, $new_map);
                    }

                    $new_map['page'] = $i;

                    $link = sprintf('<LI class="owa_reportPaginationControl"><a href="%s">%s</a></LI>',
                                                        $this->makeLink($new_map, $add_state),
                                                        $i);

                } else {

                    $link = sprintf('<LI class="owa_reportPaginationControl">%s</LI>', $i);
                }

                $pages .= $link;
            }

            $pages .= '</UL></div>';



        }

        return $pages;
    }

    /**
     * @param  string $name
     * @return mixed  a view var, any type; false when it is not set
     */
    public function get($name) {

        if (array_key_exists($name, $this->vars)) {
            return $this->vars[$name];
        } else {
            return false;
        }

    }

    /**
     * @param  int|string $key
     * @param  mixed      $var  only an array is looked in
     * @return mixed      $var[$key], any type; null when it is not there
     */
    public function getValue( $key, $var) {

        if ( isset( $var ) && is_array( $var ) ) {
            if ( array_key_exists( $key, $var) ) {
                return $var[$key];
            }
        }
    }


    /**
     * The rail: one entry per report section, then Settings at its foot.
     *
     * Each entry carries its own reports as `items`, which are the column
     * beside the rail when the section is current and the flyout when it is
     * not. A section with no reports under it (Dashboard, Realtime) has none,
     * and opens no column.
     *
     * The section's own report heads its items, as "Overview" unless the
     * section names it (overview_label). An item pointing where the section
     * itself does is dropped, so it is not listed twice. An item may carry a
     * `heading`, which reportColumn() groups it under, and an `icon_class`.
     *
     * A section with `keeps_column` has its column even when nothing is under
     * it yet (Custom Reports, before anything is a favorite). One with
     * `current_for` is also current on those screens: each is a set of params
     * that must all match, and a value ending in `*` matches by its prefix.
     *
     * @param array<array<string, mixed>> $nav the Reports navigation group: nav link structs
     * @param string|null $propertyId the Property every report link is of
     * @param array<string, mixed> $params the request's params, to mark what is current
     * @param string $accessSiteId a Profile of the Property, which each link's
     *                           capability is checked against
     * @param bool   $inSettings whether this screen is a settings screen
     * @return list<RailSection>
     */
    public function navRail( $nav, $propertyId, $params = [], $accessSiteId = '', $inSettings = false ) {

        $user  = $this->getCurrentUser();
        $state = $propertyId !== '' && $propertyId !== null ? [ 'propertyId' => $propertyId ] : [];
        $rail  = [];

        foreach ( (array) $nav as $key => $section ) {

            if ( ! $user->isCapable( $section['priviledge'] ?? '', $accessSiteId ) ) {

                continue;
            }

            $own   = $this->navLinkParams( $section );
            $items = [];

            if ( ! empty( $section['subgroup'] ) || ! empty( $section['keeps_column'] ) ) {

                $items[] = [
                    'label'   => (string) ( $section['overview_label'] ?? \OWA\Core\CoreAPI::t( 'Overview' ) ),
                    'href'    => $this->makeLink( array_merge( $state, $own ), true ),
                    'current' => ! $inSettings && $this->navLinkIsCurrent( $section, $params ),
                ];

                foreach ( (array) ( $section['subgroup'] ?? [] ) as $link ) {

                    if ( $this->navLinkParams( $link ) == $own
                         || ! $user->isCapable( $link['priviledge'] ?? '', $accessSiteId ) ) {

                        continue;
                    }

                    $items[] = [
                        'label'   => strip_tags( (string) $link['anchortext'] ),
                        'href'    => $this->makeLink( array_merge( $state, $this->navLinkParams( $link ) ), true ),
                        'current' => ! $inSettings && $this->navLinkIsCurrent( $link, $params ),
                        'heading' => (string) ( $link['heading'] ?? '' ),
                        'icon'    => trim( (string) ( $link['icon_class'] ?? '' ) ),
                    ];
                }
            }

            $current = ! $inSettings
                && ( $this->navLinkIsCurrent( $section, $params ) || $this->navIsCurrentFor( $section, $params ) );

            foreach ( $items as $item ) {

                $current = $current || $item['current'];
            }

            $rail[] = [
                'id'      => (string) $key,
                'label'   => strip_tags( (string) $section['anchortext'] ),
                'icon'    => trim( (string) ( $section['icon_class'] ?? '' ) ) ?: \OWA\Core\Module::DEFAULT_NAV_ICON,
                'href'    => $this->makeLink( array_merge( $state, $own ), true ),
                'current' => $current,
                'items'   => $items,
                'foot'    => false,
            ];
        }

        /*
         * Settings, at the foot. Everyone signed in has a settings screen of
         * their own (My preferences), so the gear is there for every reader and
         * opens the first screen they may use.
         */
        if ( $user->isCapable( 'view_site_list' ) ) {

            $rail[] = [
                'id'      => 'settings',
                'label'   => \OWA\Core\CoreAPI::t( 'Settings' ),
                'icon'    => 'fas fa-cog',
                // With the Property and the period, so the settings screens are
                // of the one being looked at and the way back keeps the period.
                'href'    => $this->makeLink( [
                    'do' => $user->isCapable( 'edit_settings' ) ? 'base.optionsGeneral' : 'base.myProfile' ],
                    \OWA\Core\Controller::reportState() ),
                'current' => (bool) $inSettings,
                'items'   => [],
                'foot'    => true,
            ];
        }

        return $rail;
    }

    /**
     * Whether the request is one of the screens a section also answers for.
     *
     * @param array<string, mixed> $section a nav section, with `current_for` param sets
     * @param array<string, mixed> $params  the request's params
     * @return bool
     */
    private function navIsCurrentFor( $section, $params ) {

        foreach ( (array) ( $section['current_for'] ?? [] ) as $wanted ) {

            $matches = (bool) $wanted;

            foreach ( (array) $wanted as $key => $value ) {

                $value = (string) $value;
                $have  = (string) ( $params[ $key ] ?? '' );

                if ( substr( $value, -1 ) === '*'
                    ? strpos( $have, substr( $value, 0, -1 ) ) !== 0
                    : $have !== $value ) {

                    $matches = false;
                    break;
                }
            }

            if ( $matches ) {

                return true;
            }
        }

        return false;
    }

    /**
     * The column beside the rail on a report screen: the current section's
     * pages, or null when it has none.
     *
     * Items sharing a `heading` are one group, in the order they come.
     *
     * @param list<RailSection> $rail as navRail() returns it
     * @return array{title: string, groups: list<array{heading: string, items: list<RailItem>}>}|null
     */
    public static function reportColumn( $rail ) {

        foreach ( (array) $rail as $section ) {

            if ( $section['current'] && $section['items'] ) {

                $groups = [];

                foreach ( $section['items'] as $item ) {

                    $heading = (string) ( $item['heading'] ?? '' );
                    $last    = count( $groups ) - 1;

                    if ( $last < 0 || $groups[ $last ]['heading'] !== $heading ) {

                        $groups[] = [ 'heading' => $heading, 'items' => [] ];
                        $last++;
                    }

                    $groups[ $last ]['items'][] = $item;
                }

                return [
                    'title'  => $section['label'],
                    'groups' => $groups,
                ];
            }
        }

        return null;
    }

    /**
     * The settings column: the hierarchy nav's groups, headed by level.
     *
     * The keys of the hierarchy nav are what modules register into, so they
     * stay as they are; this is where they get the headings people read.
     * 'Observation Profile' is not a group of the column: its pages are the
     * tabs of the Profile's own page (profileTabs()).
     *
     * An entry is current when the request is its action with its params, or
     * one of the actions it also answers for (`current_for`) -- a Profile's
     * entry is current on every one of its tabs.
     *
     * Every entry links with the Property in the link state, so the next
     * screen is of the same one; an entry naming its own keeps that.
     *
     * @param array<string, list<SettingsEntry>> $hierarchyNav as Controller::getHierarchyNav() returns it
     * @param array<string, mixed> $params the request's params
     * @return list<array{heading: string, items: list<SettingsItem>}>
     */
    public function settingsColumn( $hierarchyNav, $params = [] ) {

        $headings = [
            'My Preferences' => \OWA\Core\CoreAPI::t( 'My preferences' ),
            'Instance'       => \OWA\Core\CoreAPI::t( 'Server configuration' ),
            'Organization'   => \OWA\Core\CoreAPI::t( 'Organization' ),
            'Property'       => \OWA\Core\CoreAPI::t( 'Property' ),
        ];

        $user    = $this->getCurrentUser();
        $groups  = [];

        foreach ( (array) $hierarchyNav as $key => $entries ) {

            if ( $key === 'Observation Profile' ) {

                continue;
            }

            $items = [];

            foreach ( (array) $entries as $entry ) {

                if ( ! empty( $entry['capability'] ) && ! $user->isCapable( $entry['capability'] ) ) {

                    continue;
                }

                $items[] = [
                    'label'   => (string) $entry['label'],
                    'href'    => $this->makeLink( array_merge( [ 'do' => $entry['do'] ], (array) ( $entry['params'] ?? [] ) ),
                        \OWA\Core\Controller::reportState() ),
                    'current' => self::settingsEntryIsCurrent( $entry, $params ),
                ];
            }

            if ( $items ) {

                $groups[] = [ 'heading' => $headings[ $key ] ?? (string) $key, 'items' => $items ];
            }
        }

        /*
         * One mark at most. Two entries can claim the same request -- Add
         * profile and a Profile's Details are both base.sitesProfile -- and the
         * first that matches is the one the reader is on.
         */
        $marked = false;

        foreach ( $groups as $g => $group ) {

            foreach ( $group['items'] as $i => $item ) {

                if ( $item['current'] && $marked ) {

                    $groups[ $g ]['items'][ $i ]['current'] = false;
                }

                $marked = $marked || $item['current'];
            }
        }

        return $groups;
    }

    /**
     * The tabs of a Profile's page: the hierarchy nav's 'Observation Profile'
     * group, on a screen that is one of them. Empty anywhere else.
     *
     * @param array<string, list<SettingsEntry>> $hierarchyNav
     * @param array<string, mixed> $params
     * @return list<SettingsItem>
     */
    public function profileTabs( $hierarchyNav, $params = [] ) {

        $entries = (array) ( $hierarchyNav['Observation Profile'] ?? [] );
        $user    = $this->getCurrentUser();
        $tabs    = [];
        $onOne   = false;

        foreach ( $entries as $entry ) {

            if ( ! empty( $entry['capability'] ) && ! $user->isCapable( $entry['capability'] ) ) {

                continue;
            }

            $current = ( $params['do'] ?? '' ) === $entry['do'];
            $onOne   = $onOne || $current;

            // A module's registered tab names no Profile; it is this one's.
            $linkParams = (array) ( $entry['params'] ?? [] );

            if ( ! isset( $linkParams['siteId'] ) && ! empty( $params['siteId'] ) ) {

                $linkParams['siteId'] = $params['siteId'];
            }

            $tabs[] = [
                'label'   => (string) $entry['label'],
                'href'    => $this->makeLink( array_merge( [ 'do' => $entry['do'] ], $linkParams ) ),
                'current' => $current,
            ];
        }

        return $onOne ? $tabs : [];
    }

    /**
     * Whether a settings nav entry is the screen being looked at.
     *
     * Its action, or one it also answers for, and every param it names --
     * `edit` aside, which says how the screen is opened rather than which
     * thing it is about.
     *
     * @param SettingsEntry $entry  a hierarchy nav entry
     * @param array<string, mixed> $params the request's params
     * @return bool
     */
    public static function settingsEntryIsCurrent( $entry, $params ) {

        $do = (string) ( $params['do'] ?? '' );

        if ( $do !== $entry['do'] && ! in_array( $do, (array) ( $entry['current_for'] ?? [] ), true ) ) {

            return false;
        }

        $wanted = (array) ( $entry['params'] ?? [] );
        unset( $wanted['edit'] );

        foreach ( $wanted as $key => $value ) {

            if ( (string) ( $params[ $key ] ?? '' ) !== (string) $value ) {

                return false;
            }
        }

        return true;
    }

    /**
     * Creates a hidden nonce form field
     *
     * @param     string    $action the action that the nonce should be tied to.
     * @return    string The html fragment
     */
    public function createNonceFormField($action) {

        return sprintf(
                '<input type="hidden" name="nonce" value="%s">',
                \OWA\Core\CoreAPI::createNonce($action));
    }


    /**
     * Code to copy, in a box with a copy button: the tracking tag and anything
     * else a person is told to paste somewhere.
     *
     * Scrolls sideways rather than wrapping, so what is copied is exactly what
     * is shown, and never widens the page. The button is wired by
     * owa.copy.js; without it the code is still selectable.
     *
     * @param string $code     shown and copied as-is (escaped for display)
     * @param string $language the label in the box's top bar
     * @return string HTML
     */
    public function codeBlock( $code, $language = 'HTML' ) {

        $e = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };

        return '<div class="owa-codeBlock">'
             . '<div class="owa-codeBlock__bar">'
             . '<span class="owa-codeBlock__language">' . $e( $language ) . '</span>'
             . '<button type="button" class="owa-codeBlock__copy" data-owa-copy aria-label="' . $e( \OWA\Core\CoreAPI::t( 'Copy to clipboard' ) ) . '">'
             . '<i class="far fa-copy" aria-hidden="true"></i><span class="owa-codeBlock__copyLabel">' . $e( \OWA\Core\CoreAPI::t( 'Copy' ) ) . '</span>'
             . '</button>'
             . '</div>'
             . '<pre class="owa-codeBlock__code"><code>' . $e( trim( (string) $code, "\r\n" ) ) . '</code></pre>'
             . '</div>';
    }

    /**
     * Outputs data into the template
     *
     * @param    mixed   $output     the value to print, any type: escape() makes
     *                               it a string; unsanitized it is echoed as is
     * @param    bool    $sanitize    Flag that will sanitize the output for display
     * @param    bool    $decode_special_entities whether an escaped & is printed as &
     * @return   void
     */
    public function out($output, $sanitize = true, $decode_special_entities = false) {

        if ( $sanitize ) {
            $output = $this->escape($output);

            if ( $decode_special_entities ) {
                $output = strtr($output, ['&amp;'  => '&']);
            }

        }

        echo $output;
    }

    /**
     * A value escaped for HTML, text or attribute, as out() prints it. The one
     * escape templates use; out() is this, echoed.
     *
     * @param mixed $value
     * @return string
     */
    public static function escape( $value ) {

        return (string) \OWA\Module\Base\Classes\Sanitize::escapeForDisplay( $value );
    }

    /**
     * A value as JSON that is safe inside a <script> element or an attribute:
     * < > & ' " are written as \u escapes, so no value can close the element
     * or the attribute. $flags adds to those (JSON_FORCE_OBJECT, ...).
     *
     * @param mixed $value
     * @param int   $flags
     * @return string|false
     */
    public static function scriptJson( $value, $flags = 0 ) {

        return json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | (int) $flags );
    }

    /**
     * Outputs a translated sentence with markup in it: the sentence escaped as
     * out() escapes, each placeholder filled with markup as given.
     *
     *   $view->outMarkup( \OWA\Core\CoreAPI::t( 'Send it in the %s header.' ), '<code>Authorization</code>' );
     *
     * The markup is not escaped: escape any value in it.
     *
     * @param string $text      the sentence, from CoreAPI::t() or tn()
     * @param string ...$markup HTML for its placeholders, in order
     * @return void
     */
    public function outMarkup( $text, ...$markup ) {

        echo vsprintf( $this->escape( (string) $text ), array_map( 'strval', $markup ) );
    }

    /**
     * Safely emit a tracker-sourced URL into an href/src attribute.
     *
     * escapeForDisplay() (what out() uses) neutralizes attribute breakout
     * ("><script) but does NOT neutralize a scheme-based payload: a stored
     * value of  javascript:alert(1)  or  data:text/html,...  survives
     * htmlentities() unchanged and stays a live scheme in an href. Stored
     * URLs are attacker-controllable (set by the tracker) and are NOT
     * re-escaped by makeUrlCanonical(), so any template that drops a stored
     * URL straight into href="" needs a scheme check on top of escaping.
     *
     * This permits only http/https/mailto/ftp (and scheme-relative "//" and
     * root-relative "/" URLs); anything else is replaced with '#'. The
     * returned value is then escaped exactly like out() for the breakout case.
     *
     * @param string $url  the URL to emit
     * @param bool   $echo when true (default) echo the value, else return it
     * @return string the value, escaped
     */
    public function safeHref( $url, $echo = true ) {

        $safe = \OWA\Module\Base\Classes\Sanitize::sanitizeHref( $url );
        $safe = $this->escape( $safe );

        if ( $echo ) {
            echo $safe;
        }

        return $safe;
    }

    /**
     * @param  int|float $value minor units of the install's currency
     * @return string
     */
    public function formatCurrency($value) {
        return \OWA\Core\Lib::formatCurrency( $value, \OWA\Core\CoreAPI::getSetting( 'base', 'currencyLocal' ), \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' ) );
    }

    /**
     * @return \OWA\Module\Base\Classes\ServiceUser
     */
    public function getCurrentUser() {

        return \OWA\Core\CoreAPI::getCurrentUser();
    }


}
