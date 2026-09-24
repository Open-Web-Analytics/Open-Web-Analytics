<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Builds a settings form out of the registry.
 *
 * WHAT THIS REPLACES. Every settings screen hand-wrote its controls: a <select>
 * with Off and On spelled out, a title div, a description div, and the field
 * name assembled by hand as `config[module.key]`. Eight fields of that on the
 * general options page alone, and each one an independent chance to write the
 * wrong key -- a typo there does not error, it saves a setting nothing reads.
 *
 * The declaration already says what each setting is. `type` says what control
 * it takes, `options` says what a select offers, `label` and `description` say
 * what to call it. A screen that reads those cannot disagree with the registry
 * about what a setting is called or whether it can be saved at all, because it
 * is not repeating either.
 *
 * WHAT IT FIXES ALONG THE WAY. A setting supplied by a config-file constant is
 * unsettable from a form -- the constant wins on every boot -- and until now
 * exactly one field said so, because someone hand-wrote the check for
 * base.timezone. Every other field accepted an edit that silently did nothing.
 * The check is in the renderer, so it is on every field.
 *
 * DESCRIPTIONS ARE HTML, and deliberately: they carry <strong> and <br>, and
 * they come from declaration files in the repository, never from a request.
 * Values, labels, keys and option text are all escaped.
 */
class SettingsForm {

    /**
     * The fieldsets a registered settings page asks for, in the order it asked.
     *
     * The page names its fieldsets and each fieldset names its settings, so a
     * screen is three declarations deep and none of them repeats the one below.
     * A page that names a fieldset nobody registered is skipped here and
     * reported by fieldSetProblems(); rendering a legend with nothing under it
     * would show the mistake to the wrong person.
     *
     * @param  string $do     the page's action name, e.g. base.optionsGeneral
     * @param  array|null $panels where to look, defaulting to every loaded
     *                    module's. A module registers its pages only when it is
     *                    ACTIVE, and is_active lives in the database, so passing
     *                    one module's own panels is how this is exercised
     *                    without an activated install.
     * @return array ordered fieldset declarations
     */
    public static function pageFieldSets( $do, $panels = null ) {

        if ( $panels === null ) {

            $panels = (array) \OWA\Core\CoreAPI::singleton()->getAdminPanels();
        }

        $page = null;

        foreach ( (array) $panels as $items ) {

            foreach ( (array) $items as $item ) {

                if ( (string) ( $item['do'] ?? '' ) === (string) $do ) {

                    $page = $item;
                    break 2;
                }
            }
        }

        if ( ! $page ) {

            return array();
        }

        $registered = (array) \OWA\Core\CoreAPI::configSingleton()->registeredFieldSets();

        $sets = array();

        foreach ( (array) ( $page['fieldsets'] ?? array() ) as $id ) {

            if ( isset( $registered[ $id ] ) ) {

                $sets[] = $registered[ $id ];
            }
        }

        return $sets;
    }

    /**
     * One fieldset, as HTML.
     *
     * @param  array  $set    from Settings::registeredFieldSets()
     * @param  string $ns     the request namespace, from View::getNs()
     * @return string
     */
    public static function fieldSet( array $set, $ns = '' ) {

        $module = (string) ( $set['module'] ?? '' );

        $fields = '';

        foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {

            $fields .= self::field( $module, (string) $key, $ns );
        }

        if ( $fields === '' ) {

            return '';
        }

        $legend = isset( $set['legend'] )
            ? sprintf( "    <legend>%s</legend>\n", self::esc( $set['legend'] ) )
            : '';

        return sprintf(
            "<fieldset name=\"owa-options\" class=\"options\" id=\"%s\">\n%s%s</fieldset>\n",
            self::esc( (string) ( $set['id'] ?? '' ) ), $legend, $fields );
    }

    /**
     * One setting, as a .setting block.
     *
     * An unregistered or unstorable setting renders NOTHING rather than an
     * empty row or a control whose save is refused. fieldSetProblems() reports
     * it, and cmd=instance-info prints that -- the screen's job is not to
     * display the mistake to whoever is trying to use it.
     *
     * @param  string $module
     * @param  string $key
     * @param  string $ns
     * @return string
     */
    public static function field( $module, $key, $ns = '' ) {

        $c = \OWA\Core\CoreAPI::configSingleton();

        $args = $c->registeredField( $module, $key );

        if ( ! $args ) {

            return '';
        }

        $constant = $c->configFileConstantFor( $module, $key );

        /*
         * A GOVERNED SETTING IS NOT A STATIC ONE, even though isStorable() says
         * no to both.
         *
         * It says no for opposite reasons. A static setting has no stored value
         * and no screen should offer one. A governed setting has a value very
         * much in force -- it is just coming from owa-config.php, and the
         * constant beats anything stored on every boot. Hiding the row would
         * leave an operator looking at a settings page that does not mention a
         * setting they can see taking effect.
         *
         * So it renders: disabled, showing the value in force, and naming the
         * constant so there is somewhere to go and change it.
         */
        if ( ! $constant && ! \OWA\Module\Base\Classes\Settings::isStorable( $args ) ) {

            return '';
        }

        $control = self::control( $module, $key, $args, $ns, (bool) $constant );

        if ( $control === '' ) {

            return '';
        }

        $description = isset( $args['description'] ) ? (string) $args['description'] : '';

        if ( $constant ) {

            $description .= self::constantNote( $constant );
        }

        return sprintf(
            "    <div class=\"setting\" id=\"%s\">\n"
          . "        <div class=\"title\">%s</div>\n"
          . "        <div class=\"description\">%s</div>\n"
          . "        <div class=\"field\">%s</div>\n"
          . "    </div>\n",
            self::esc( $key ),
            self::esc( self::label( $key, $args ) ),
            $description,
            $control );
    }

    /**
     * The control itself.
     *
     * @param  string $module
     * @param  string $key
     * @param  array  $args     the declaration
     * @param  string $ns
     * @param  bool   $disabled governed by a constant
     * @return string
     */
    protected static function control( $module, $key, array $args, $ns, $disabled ) {

        $name = sprintf( '%sconfig[%s.%s]', $ns, $module, $key );

        /*
         * A SECRET IS NEVER PRINTED, whatever else is true of it.
         *
         * Governed fields render read-only so an operator can see the value in
         * force -- which is right for a timezone and wrong for a database
         * password. The declaration says which is which; the renderer does not
         * guess from the name.
         */
        $value = empty( $args['secret'] )
            ? \OWA\Core\CoreAPI::getSetting( $module, $key )
            : '';

        $type = isset( $args['type'] ) ? (string) $args['type'] : 'text';

        $off = $disabled ? ' disabled="disabled"' : '';

        switch ( $type ) {

            case 'boolean':

                return self::select( $name, self::boolOptions(), $value ? '1' : '0', $off );

            case 'select':

                return self::select( $name, self::options( $args ), (string) $value, $off );

            case 'timezone':

                return self::select( $name, self::timezones(), (string) $value, $off );

            case 'text':

                return sprintf( '<input type="text" size="50" name="%s" value="%s"%s>',
                    self::esc( $name ), self::esc( (string) $value ), $off );
        }

        /*
         * An unknown type renders nothing rather than guessing at a text box: a
         * declaration asking for a control that does not exist is a mistake, and
         * silently downgrading it would hide that a value is being edited by
         * something other than what the author asked for.
         */
        return '';
    }

    /**
     * A <select>, flat or grouped.
     *
     * A nested array becomes optgroups, which is what the timezone picker needs
     * and what any other grouped list would get for free.
     *
     * @param  string $name
     * @param  array  $options value => label, or group => (value => label)
     * @param  string $current
     * @param  string $off     the disabled attribute, or ''
     * @return string
     */
    protected static function select( $name, array $options, $current, $off = '' ) {

        $html = sprintf( "<select name=\"%s\"%s>", self::esc( $name ), $off );

        // Only the FIRST match is marked, because a zone can appear under more
        // than one country and two selected options is not a thing.
        $taken = false;

        foreach ( $options as $value => $label ) {

            if ( is_array( $label ) ) {

                $html .= sprintf( '<optgroup label="%s">', self::esc( (string) $value ) );

                foreach ( $label as $v => $l ) {

                    $html .= self::option( (string) $v, (string) $l, $current, $taken );
                }

                $html .= '</optgroup>';

                continue;
            }

            $html .= self::option( (string) $value, (string) $label, $current, $taken );
        }

        return $html . '</select>';
    }

    /**
     * @param  string $value
     * @param  string $label
     * @param  string $current
     * @param  bool   $taken  by reference: whether something is already selected
     * @return string
     */
    protected static function option( $value, $label, $current, &$taken ) {

        $selected = '';

        if ( ! $taken && $value === $current ) {

            $taken    = true;
            $selected = ' selected="selected"';
        }

        return sprintf( '<option value="%s"%s>%s</option>',
            self::esc( $value ), $selected, self::esc( $label ) );
    }

    /**
     * What a boolean offers. Off and On, in that order, as the screens have
     * always spelled it.
     *
     * @return array
     */
    protected static function boolOptions() {

        return array( '0' => 'Off', '1' => 'On' );
    }

    /**
     * A select's options, from the declaration.
     *
     * A list means the value is the label; a map means the key is the value.
     * Both because a declaration should be able to say `array( 'a', 'b' )` when
     * that is all it means.
     *
     * @param  array $args
     * @return array
     */
    protected static function options( array $args ) {

        $options = (array) ( $args['options'] ?? array() );

        if ( ! $options ) {

            return array();
        }

        if ( array_keys( $options ) === range( 0, count( $options ) - 1 ) ) {

            return array_combine( $options, $options );
        }

        return $options;
    }

    /**
     * The IANA zones, grouped by country.
     *
     * require, NOT require_once: these two files exist only to assign their
     * arrays into this scope, so a second call in the same process would find
     * them undefined and the picker would come out empty.
     *
     * @return array country name => (zone => display name)
     */
    protected static function timezones() {

        /** @var array<string, array<string>> $timezones */
        /** @var array<string, string> $countryCode2Name */
        require( OWA_DIR . 'conf/country2Timezones.php' );
        require( OWA_DIR . 'conf/countryCodes2Names.php' );

        ksort( $timezones );

        $grouped = array();

        foreach ( $timezones as $country => $zones ) {

            $name = isset( $countryCode2Name[ $country ] )
                ? $countryCode2Name[ $country ]
                : 'unknown - ' . $country;

            foreach ( (array) $zones as $zone ) {

                $grouped[ $name ][ $zone ] = str_replace( '_', ' ', $zone );
            }
        }

        return $grouped;
    }

    /**
     * Why a field is disabled, and where to go instead.
     *
     * The disabling is a courtesy -- a disabled field is not submitted -- and
     * OptionsUpdate refuses the key regardless. The refusal is the guarantee.
     *
     * @param  string $constant
     * @return string
     */
    protected static function constantNote( $constant ) {

        return sprintf(
            '<div class="description" style="opacity:.75">Set by <code>%s</code> in '
          . '<code>owa-config.php</code>, which overrides any value stored here. Change '
          . 'it there, or remove the constant to edit it from this page.</div>',
            self::esc( $constant ) );
    }

    /**
     * A field's title. The key is a poor label but a better one than nothing,
     * and it names what is missing.
     *
     * @param  string $key
     * @param  array  $args
     * @return string
     */
    protected static function label( $key, array $args ) {

        return isset( $args['label'] ) && $args['label'] !== ''
            ? (string) $args['label']
            : $key;
    }

    /**
     * @param  mixed $text
     * @return string
     */
    protected static function esc( $text ) {

        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
