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

        // And every fieldset in a group the page names, which is how a module's
        // fieldset reaches a page another module owns.
        foreach ( (array) ( $page['fieldset_groups'] ?? array() ) as $group ) {

            foreach ( self::groupFieldSets( $group ) as $set ) {

                $sets[] = $set;
            }
        }

        return $sets;
    }

    /**
     * Every registered fieldset in a group, by its declared `order` (default
     * 50), then the order they registered -- which follows module load order,
     * so it cannot be what puts the screen's own fieldset first.
     *
     * A group lets a screen show fieldsets it does not know by name: Base owns
     * the tracking tag screens, and an active module adds its own fieldset to
     * them without Base naming it.
     *
     * @param  string $group
     * @return array
     */
    public static function groupFieldSets( $group ) {

        $out = array();

        foreach ( (array) \OWA\Core\CoreAPI::configSingleton()->registeredFieldSets() as $set ) {

            if ( (string) ( $set['group'] ?? '' ) === (string) $group ) {

                $out[] = $set;
            }
        }

        // usort is stable from PHP 8.0, so equal orders keep registration order.
        usort( $out, function ( $a, $b ) {

            return (int) ( $a['order'] ?? 50 ) <=> (int) ( $b['order'] ?? 50 );
        } );

        return $out;
    }

    /**
     * One registered fieldset by its id, or an empty one when nothing is
     * registered under it, which renders nothing.
     *
     * @param  string $id
     * @return array
     */
    public static function registeredFieldSet( $id ) {

        $registered = (array) \OWA\Core\CoreAPI::configSingleton()->registeredFieldSets();

        return isset( $registered[ $id ] ) ? (array) $registered[ $id ] : array();
    }

    /**
     * One fieldset, as HTML.
     *
     * @param  array  $set    from Settings::registeredFieldSets()
     * @param  string $ns     a prefix for each field's name; OWA's own forms use none
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

        $id      = self::controlId( $module, $key );
        $control = self::control( $module, $key, $args, $ns, (bool) $constant,
            self::storedValue( $module, $key, $args ), sprintf( ' id="%s"', self::esc( $id ) ) );

        if ( $control === '' ) {

            return '';
        }

        $description = isset( $args['description'] ) ? (string) $args['description'] : '';

        if ( $constant ) {

            $description .= self::constantNote( $constant );
        }

        return sprintf(
            "    <div class=\"setting\" id=\"%s\" data-ui=\"setting:%s\">\n"
          . "        <label class=\"title\" for=\"%s\">%s</label>\n"
          . "        <div class=\"description\">%s</div>\n"
          . "        <div class=\"field\">%s</div>\n"
          . "    </div>\n",
            self::esc( $key ),
            self::esc( $module . '.' . $key ),
            self::esc( $id ),
            self::esc( self::label( $key, $args ) ),
            $description,
            $control );
    }

    /**
     * What a level is called where a screen names it ("Set at the %s level."),
     * in the locale in use; a level it does not know, as given.
     *
     * @param  string $level install, organization, property or profile
     * @return string
     */
    public static function levelName( $level ) {

        switch ( $level ) {

            case 'install':
                return \OWA\Core\CoreAPI::t( 'install', 'settings level' );

            case 'organization':
                return \OWA\Core\CoreAPI::t( 'Organization', 'settings level' );

            case 'property':
                return \OWA\Core\CoreAPI::t( 'Property', 'settings level' );

            case 'profile':
                return \OWA\Core\CoreAPI::t( 'Observation Profile', 'settings level' );
        }

        return (string) $level;
    }

    /**
     * One fieldset for a level below the install, as HTML.
     *
     * @param  array       $set
     * @param  string      $scopeType  property or profile
     * @param  string|null $scopeId    null for one not created yet
     * @param  string      $ns
     * @param  array|null  $posted     a refused post to redisplay: array( 'config' => ..., 'override' => ... )
     * @param  bool        $collapsible as a closed group, its legend and description the heading a person
     *                                  opens it by. Open when redisplaying a refused post, so a refused value
     *                                  is not hidden from the person who has to fix it.
     * @return string
     */
    public static function scopedFieldSet( array $set, $scopeType, $scopeId, $ns = '', $posted = null, $collapsible = false ) {

        $module = (string) ( $set['module'] ?? '' );

        $fields = '';

        foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {

            $fields .= self::scopedField( $module, (string) $key, $scopeType, $scopeId, $ns, $posted );
        }

        if ( $fields === '' ) {

            return '';
        }

        if ( $collapsible ) {

            return sprintf(
                "<details class=\"owa-settingsGroup\" id=\"%s\"%s>\n"
              . "    <summary><span class=\"owa-settingsGroup__title\">%s</span>%s</summary>\n"
              . "<fieldset name=\"owa-options\" class=\"options\">\n%s</fieldset>\n</details>\n",
                self::esc( (string) ( $set['id'] ?? '' ) ),
                is_array( $posted ) ? ' open' : '',
                self::esc( (string) ( $set['legend'] ?? '' ) ),
                isset( $set['description'] )
                    ? sprintf( '<span class="owa-settingsGroup__description">%s</span>', self::esc( $set['description'] ) )
                    : '',
                $fields );
        }

        $legend = isset( $set['legend'] )
            ? sprintf( "    <legend>%s</legend>\n", self::esc( $set['legend'] ) )
            : '';

        return sprintf(
            "<fieldset name=\"owa-options\" class=\"options\" id=\"%s\">\n%s%s</fieldset>\n",
            self::esc( (string) ( $set['id'] ?? '' ) ), $legend, $fields );
    }

    /**
     * One setting at a level below the install.
     *
     * INHERITING, the value in force is shown as text, as the screen names it
     * (displayValue()), with the level that sets it beside it and an Override
     * switch, off. The control is in the page but hidden and disabled, so it
     * posts nothing. Turning the switch on hides the text and shows the control,
     * enabled and holding the inherited value; turning it off puts that value
     * back and hides the control again (src/reporting/v1/owa.settingsoverride.js).
     * Saving with the switch on stores the value at this level, and saving with
     * it off removes any value stored here. The switch is the whole decision --
     * a value equal to the inherited one is still an override when the switch
     * says so.
     *
     * The title labels the control. The text is labelled by the title and
     * described by the level note, and the switch is named for the setting.
     *
     * With nothing set above -- no stored value and no default -- there is
     * nothing to override, so the field is plain: editable, with no switch and
     * no note.
     *
     * Renders nothing for a setting this level may not hold. A setting a config
     * constant governs renders as it does on the install screen, read-only and
     * naming the constant, with no switch: no level can override it.
     *
     * @param  string      $module
     * @param  string      $key
     * @param  string      $scopeType
     * @param  string|null $scopeId
     * @param  string      $ns
     * @param  array|null  $posted  see scopedFieldSet()
     * @return string
     */
    public static function scopedField( $module, $key, $scopeType, $scopeId, $ns = '', $posted = null ) {

        $c = \OWA\Core\CoreAPI::configSingleton();

        $args = $c->registeredField( $module, $key );

        if ( ! $args || ! in_array( $scopeType, (array) $c->scopesFor( $module, $key ), true ) ) {

            return '';
        }

        if ( $c->configFileConstantFor( $module, $key ) ) {

            return self::field( $module, $key, $ns );
        }

        if ( ! \OWA\Module\Base\Classes\Settings::isStorable( $args ) ) {

            return '';
        }

        $state = self::inheritance( $module, $key, $scopeType, $scopeId );
        $name  = $module . '.' . $key;
        $id    = self::controlId( $module, $key );

        /*
         * NOTHING ABOVE TO OVERRIDE -- no stored value and no default -- so a
         * plain field: no switch and no note. A value saved in it is this
         * level's, and saving it empty removes it (saveScoped()).
         */
        if ( self::hasNoValue( $state['inherited'], $args ) ) {

            $value = $state['own'] ? $state['value'] : '';

            if ( is_array( $posted ) && array_key_exists( $name, (array) ( $posted['config'] ?? array() ) ) ) {

                $value = $posted['config'][ $name ];
            }

            $control = self::control( $module, $key, $args, $ns, false, $value,
                sprintf( ' id="%s"', self::esc( $id ) ) );

            return $control === '' ? '' : self::settingBlock( $module, $key, $args, $control, '', '', $id );
        }

        $overriding = $state['own'];
        $value      = $overriding ? $state['value'] : $state['inherited'];

        if ( is_array( $posted ) ) {

            $overriding = ! empty( $posted['override'][ $name ] );

            if ( ! $overriding ) {

                $value = $state['inherited'];

            } elseif ( array_key_exists( $name, (array) ( $posted['config'] ?? array() ) ) ) {

                $value = $posted['config'][ $name ];
            }
        }

        $type = isset( $args['type'] ) ? (string) $args['type'] : 'text';

        if ( $type === 'boolean' ) {

            $inherited = $state['inherited'] ? '1' : '0';

        } elseif ( $type === 'map' ) {

            $inherited = \OWA\Module\Base\Classes\Settings::mapText( $args, $state['inherited'] );

        } else {

            $inherited = is_array( $state['inherited'] ) ? implode( ', ', $state['inherited'] ) : (string) $state['inherited'];
        }

        if ( $type === 'boolean' ) {

            $value = $value ? '1' : '0';

        } elseif ( is_array( $value ) && $type !== 'map' ) {

            // A list is edited as the text it is shown as.
            $value = implode( ', ', $value );
        }

        $control = self::control( $module, $key, $args, $ns, ! $overriding, $value,
            sprintf( ' id="%s" data-owa-inherited="%s"%s', self::esc( $id ), self::esc( $inherited ),
                $overriding ? '' : ' hidden' ) );

        if ( $control === '' ) {

            return '';
        }

        $level = self::levelName( $state['from'] );
        $shown = self::displayValue( $args, $state['inherited'] );
        $label = self::label( $key, $args );

        if ( $shown === '' ) {

            $text  = sprintf( '<span class="owa-inherited__none">%s</span>', self::esc( \OWA\Core\CoreAPI::t( 'Not set' ) ) );
            $class = '';

        } else {

            $text  = self::esc( $shown );
            $class = self::isLongValue( $shown ) ? ' owa-inherited--long' : '';
        }

        // A long value is cut to one line; focused or hovered it shows in full, so it takes the focus.
        $text = sprintf(
            '<output class="owa-inherited%s" id="%s-inherited" aria-labelledby="%s-title" aria-describedby="%s-note-inherit"'
          . ' aria-live="off" data-ui="setting-inherited"%s%s>%s</output>',
            $class, self::esc( $id ), self::esc( $id ), self::esc( $id ),
            $class !== '' ? ' tabindex="0"' : '',
            $overriding ? ' hidden' : '',
            $text );

        $inheritNote = sprintf(
            '<span class="owa-inherit-note" id="%1$s-note-inherit" data-owa-note-inherit="%1$s"%2$s>%3$s</span>',
            self::esc( $id ),
            $overriding ? ' hidden' : '',
            sprintf( self::esc( \OWA\Core\CoreAPI::t( 'Set at the %s level.' ) ), self::esc( $level ) ) );

        $switch = sprintf(
            '<label class="owa-override"><input type="checkbox" role="switch" name="%s" value="1"'
          . ' data-owa-override="%s" data-ui="setting-override" aria-label="%s" aria-describedby="%s"%s> %s</label>',
            self::esc( sprintf( '%soverride[%s]', $ns, $name ) ), self::esc( $id ),
            self::esc( sprintf( \OWA\Core\CoreAPI::t( 'Override %s' ), $label ) ),
            self::esc( $overriding ? $id . '-note-override' : $id . '-inherited ' . $id . '-note-inherit' ),
            $overriding ? ' checked="checked"' : '',
            self::esc( \OWA\Core\CoreAPI::t( 'Override' ) ) );

        $notes = sprintf(
            '<div class="owa-inherit-note" id="%1$s-note-override" data-owa-note-override="%1$s"%2$s>%3$s</div>',
            self::esc( $id ),
            $overriding ? '' : ' hidden',
            sprintf( self::esc( \OWA\Core\CoreAPI::t( 'Overrides the %1$s level value of %2$s.' ) ),
                self::esc( $level ),
                $shown === '' ? self::esc( \OWA\Core\CoreAPI::t( 'Not set' ) ) : '<code>' . self::esc( $shown ) . '</code>' ) );

        return self::settingBlock( $module, $key, $args,
            '<div class="owa-overridable__value">' . $control . $text . $inheritNote . '</div>' . $switch,
            $notes, 'owa-overridable', $id );
    }

    /** Longer than this, an inherited value is shown on one line, cut, until it is focused or hovered. */
    const LONG_VALUE = 60;

    /**
     * @param  string $text
     * @return bool
     */
    protected static function isLongValue( $text ) {

        return ( function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ) ) > self::LONG_VALUE;
    }

    /**
     * Whether a value is no value at all: null, an empty string or an empty
     * list, and false for anything but a boolean -- a setting with no default
     * answers false. 0 is a value, and so is a boolean's false.
     *
     * @param  mixed $value
     * @param  array $args  the declaration
     * @return bool
     */
    protected static function hasNoValue( $value, array $args = array() ) {

        if ( $value === false ) {

            return ( $args['type'] ?? '' ) !== 'boolean';
        }

        return $value === null || $value === array()
            || ( is_string( $value ) && trim( $value ) === '' );
    }

    /**
     * A scoped setting's .setting block.
     *
     * @param  string $module
     * @param  string $key
     * @param  array  $args
     * @param  string $field  the control, and its switch when it has one
     * @param  string $notes
     * @param  string $class  a further class for the field row
     * @param  string $for    the control's id, which the title labels; the title's own id is this plus -title
     * @return string
     */
    protected static function settingBlock( $module, $key, array $args, $field, $notes, $class = '', $for = '' ) {

        return sprintf(
            "    <div class=\"setting\" id=\"%s\" data-ui=\"setting:%s\">\n"
          . "        <label class=\"title\" id=\"%s-title\" for=\"%s\">%s</label>\n"
          . "        <div class=\"description\">%s</div>\n"
          . "        <div class=\"field%s\">%s</div>\n"
          . "%s"
          . "    </div>\n",
            self::esc( $key ),
            self::esc( $module . '.' . $key ),
            self::esc( $for ),
            self::esc( $for ),
            self::esc( self::label( $key, $args ) ),
            isset( $args['description'] ) ? (string) $args['description'] : '',
            $class !== '' ? ' ' . $class : '',
            $field,
            $notes !== '' ? '        ' . $notes . "\n" : '' );
    }

    /**
     * Where a level's value for a setting comes from.
     *
     * @param  string      $module
     * @param  string      $key
     * @param  string      $scopeType
     * @param  string|null $scopeId   null or '' for a level not created yet,
     *                                which holds nothing and inherits the install
     * @return array own (bool), value (this level's), inherited (the value in
     *               force without it), from (the level that supplies that)
     */
    public static function inheritance( $module, $key, $scopeType, $scopeId ) {

        $out = array( 'own' => false, 'value' => null, 'inherited' => null, 'from' => 'install' );

        if ( $scopeId !== null && $scopeId !== '' ) {

            $row = \OWA\Core\CoreAPI::getScopedSettingRow( $scopeType, (string) $scopeId, $module, $key );

            if ( $row !== null ) {

                $out['own']   = true;
                $out['value'] = $row;
            }

            foreach ( array_slice( (array) \OWA\Core\CoreAPI::settingScopeChain( $scopeType, (string) $scopeId ), 1 ) as $scope ) {

                $row = \OWA\Core\CoreAPI::getScopedSettingRow( $scope['type'], $scope['id'], $module, $key );

                if ( $row !== null ) {

                    $out['inherited'] = $row;
                    $out['from']      = $scope['type'];

                    return $out;
                }
            }
        }

        $out['inherited'] = \OWA\Core\CoreAPI::getSetting( $module, $key );

        return $out;
    }

    /**
     * Save a scoped fieldset from a post: each field's switch decides, or, for
     * a plain field with nothing above it, its value (empty removes it).
     *
     * On, the posted value is stored at this level -- or, when none arrived
     * (the control stays disabled without script), the value it was showing,
     * so the level keeps what it saw. Off, any value stored here is removed.
     * Only the fieldset's own settings are touched, and only those
     * scopedField() would render, so a post cannot reach a setting the screen
     * does not offer.
     *
     * @param  array  $set
     * @param  string $scopeType
     * @param  string $scopeId
     * @param  array  $config    posted config[module.key]
     * @param  array  $override  posted override[module.key]
     * @return bool   every write succeeded
     */
    public static function saveScoped( array $set, $scopeType, $scopeId, $config, $override ) {

        $c      = \OWA\Core\CoreAPI::configSingleton();
        $module = (string) ( $set['module'] ?? '' );
        $config   = (array) $config;
        $override = (array) $override;
        $ok     = true;

        foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {

            $key  = (string) $key;
            $args = $c->registeredField( $module, $key );

            if ( ! $args
                 || ! in_array( $scopeType, (array) $c->scopesFor( $module, $key ), true )
                 || $c->configFileConstantFor( $module, $key )
                 || ! \OWA\Module\Base\Classes\Settings::isStorable( $args ) ) {

                continue;
            }

            $name  = $module . '.' . $key;
            $state = self::inheritance( $module, $key, $scopeType, $scopeId );

            // A plain field, with nothing above to override: its value decides.
            if ( self::hasNoValue( $state['inherited'], $args ) ) {

                if ( ! array_key_exists( $name, $config ) ) {

                    continue;
                }

                if ( ! self::hasNoValue( $config[ $name ], $args ) ) {

                    $ok = \OWA\Core\CoreAPI::setScopedSetting( $scopeType, (string) $scopeId, $module, $key, $config[ $name ] ) && $ok;

                } elseif ( $state['own'] ) {

                    $ok = \OWA\Core\CoreAPI::clearScopedSetting( $scopeType, (string) $scopeId, $module, $key ) && $ok;
                }

                continue;
            }

            if ( ! empty( $override[ $name ] ) ) {

                $value = array_key_exists( $name, $config ) ? $config[ $name ] : $state['inherited'];

                if ( ( $args['type'] ?? '' ) === 'boolean' ) {

                    $value = (bool) $value;
                }

                $ok = \OWA\Core\CoreAPI::setScopedSetting( $scopeType, (string) $scopeId, $module, $key, $value ) && $ok;

            } elseif ( \OWA\Core\CoreAPI::getScopedSettingRow( $scopeType, (string) $scopeId, $module, $key ) !== null ) {

                $ok = \OWA\Core\CoreAPI::clearScopedSetting( $scopeType, (string) $scopeId, $module, $key ) && $ok;
            }
        }

        return $ok;
    }

    /**
     * What a scoped post would store that its declarations refuse, by field.
     *
     * The fields saveScoped() would write: an override switched on, or a plain
     * field given a value. A controller adds these as validation errors so the
     * form says what was wrong; the write chokepoint refuses them regardless.
     *
     * @param  array  $set
     * @param  string $scopeType
     * @param  string $scopeId
     * @param  array  $config
     * @param  array  $override
     * @return array  module.key => message
     */
    public static function scopedProblems( array $set, $scopeType, $scopeId, $config, $override ) {

        $c        = \OWA\Core\CoreAPI::configSingleton();
        $module   = (string) ( $set['module'] ?? '' );
        $config   = (array) $config;
        $override = (array) $override;
        $out      = array();

        foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {

            $key  = (string) $key;
            $name = $module . '.' . $key;

            if ( ! array_key_exists( $name, $config ) ) {

                continue;
            }

            $args  = (array) $c->registeredField( $module, $key );
            $plain = self::hasNoValue( self::inheritance( $module, $key, $scopeType, $scopeId )['inherited'], $args );

            if ( $plain ? self::hasNoValue( $config[ $name ], $args ) : empty( $override[ $name ] ) ) {

                continue;
            }

            $problem = $c->valueProblem( $module, $key, $config[ $name ] );

            if ( $problem !== null ) {

                $out[ $name ] = $problem;
            }
        }

        return $out;
    }

    /**
     * A value as the screen names it: a boolean as On or Off, a select or a
     * timezone by its option's label, a map as its name=value pairs and a list
     * comma-separated. Empty stays empty; the caller says "Not set".
     *
     * @param  array $args
     * @param  mixed $value
     * @return string
     */
    protected static function displayValue( array $args, $value ) {

        $type = isset( $args['type'] ) ? (string) $args['type'] : 'text';

        if ( $type === 'boolean' ) {

            return $value ? 'On' : 'Off';
        }

        if ( $type === 'select' ) {

            $options = self::options( $args );

            if ( isset( $options[ (string) $value ] ) ) {

                return (string) $options[ (string) $value ];
            }
        }

        if ( $type === 'timezone' && is_string( $value ) ) {

            return str_replace( '_', ' ', $value );
        }

        if ( $type === 'map' ) {

            return \OWA\Module\Base\Classes\Settings::mapText( $args, $value );
        }

        if ( is_array( $value ) ) {

            $value = implode( ', ', $value );
        }

        return (string) $value;
    }

    /** The id of a setting's control, which its title labels. */
    protected static function controlId( $module, $key ) {

        return 'owa-setting-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $module . '.' . $key );
    }

    /** The value a control shows when none is given: the setting's, except a secret's, which is never printed. */
    protected static function storedValue( $module, $key, array $args ) {

        return empty( $args['secret'] ) ? \OWA\Core\CoreAPI::getSetting( $module, $key ) : '';
    }

    /**
     * The control itself.
     *
     * @param  string $module
     * @param  string $key
     * @param  array  $args     the declaration
     * @param  string $ns
     * @param  bool   $disabled governed by a constant, or inheriting
     * @param  mixed  $value    what to show; omitted, the install's value
     * @param  string $attrs    further attributes for the control, already escaped
     * @return string
     */
    protected static function control( $module, $key, array $args, $ns, $disabled, $value = null, $attrs = '' ) {

        $name = sprintf( '%sconfig[%s.%s]', $ns, $module, $key );

        /*
         * A SECRET IS NEVER PRINTED, whatever else is true of it.
         *
         * Governed fields render read-only so an operator can see the value in
         * force -- which is right for a timezone and wrong for a database
         * password. The declaration says which is which; the renderer does not
         * guess from the name.
         */
        if ( func_num_args() < 6 ) {

            $value = self::storedValue( $module, $key, $args );
        }

        $type = isset( $args['type'] ) ? (string) $args['type'] : 'text';

        $off = ( $disabled ? ' disabled="disabled"' : '' ) . $attrs;

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

            // name=value pairs in one text box (Settings::mapPairs()), so the Override switch has one control to hold.
            case 'map':

                return sprintf( '<input type="text" size="50" name="%s" value="%s"%s>',
                    self::esc( $name ), self::esc( \OWA\Module\Base\Classes\Settings::mapText( $args, $value ) ), $off );

            case 'integer':

                // `blank` names what an empty field means, shown in it as a placeholder.
                return sprintf( '<input type="number" step="1"%s%s%s name="%s" value="%s"%s>',
                    isset( $args['min'] ) ? sprintf( ' min="%d"', $args['min'] ) : '',
                    isset( $args['max'] ) ? sprintf( ' max="%d"', $args['max'] ) : '',
                    isset( $args['blank'] ) ? sprintf( ' placeholder="%s"', self::esc( (string) $args['blank'] ) ) : '',
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
            '<div class="description" style="opacity:.75">%s</div>',
            sprintf( self::esc( \OWA\Core\CoreAPI::t( 'Set by %1$s in %2$s, which overrides any value stored here. '
                . 'Change it there, or remove the constant to edit it from this page.' ) ),
                '<code>' . self::esc( $constant ) . '</code>', '<code>owa-config.php</code>' ) );
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
