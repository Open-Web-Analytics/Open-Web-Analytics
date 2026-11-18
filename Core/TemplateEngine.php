<?php
namespace OWA\Core;

/**
 * Minimal PHP template engine — set vars, then include-and-buffer a template
 * file. OWA\Core\Template extends this with all the OWA-specific rendering
 * helpers. (Formerly the global-namespace `Template` class in
 * includes/template_class.php; relocated into Core/ during the Phase-6 PSR-4
 * migration. The old `CachedTemplate` subclass was dropped as dead code — it
 * had no instantiations or references anywhere in the tree.)
 */
// Templates set properties they do not declare; this attribute is PHP 8.2's
// supported way to allow it.
#[\AllowDynamicProperties]
class TemplateEngine {

    /**
     * Template files directory
     *
     * @var string
     */
    public $template_dir;

    /**
     * Template Variables
     *
     * @var array<string, mixed> name => the value a template reads as $view->name, any type
     */
    public $vars = [];

    /**
     * Template file, null until one is set
     *
     * @var string|null
     */
    public $file;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {

    }

    /**
     * Set the template file
     *
     * @param  string|null $file
     * @return void
     */
    public function set_template($file = null) {
        $this->file = $this->template_dir.$file;
        return;
    }

    /**
     * Set a template variable
     *
     * @param string $name
     * @param mixed $value any type; a template engine is fetched and stored as its output
     * @return void
     * @access public
     */
    public function set($name, $value) {

        if (is_object($value)) {
            if ($value instanceof $this) {
                $value = $value->fetch();
            }
        }

        $this->vars[$name] =  $value;
        return;
    }

    /**
     * Open, parse, and return the template file.
     *
     * @param string $file
     * @return string $contents
     * @access public
     */
    public function fetch($file = null) {
        if(!$file):
             $__owa_template_file = $this->file;
        else:
            $__owa_template_file = $this->template_dir.$file;
        endif;

        // A template reads its data through $view and nothing else. There is no
        // extract() -- the bare-variable contract was removed at v2.0 -- and the
        // include runs in a static closure, so $this is not in scope either.
        $view = new ViewScope($this);

        // try/finally so the buffer is always discarded, even when the template
        // throws. ViewScope::__get raises on a view var that was never set, and
        // that exception unwinds straight out of include() -- without the finally
        // the ob_start() above would leak. A leaked buffer is nastier than it
        // sounds: renders nest, so each swallowed template error leaves output
        // captured in a buffer nobody closes, and a later ob_get_contents()
        // returns unrelated markup.
        ob_start();
        try {
            ( static function ( $__owa_template_file, $view ) {
                include $__owa_template_file;
            } )( $__owa_template_file, $view );

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

}
