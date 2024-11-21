 /*
 * jQuery UI selectmenu
 *
 * Copyright (c) 2009 AUTHORS.txt (http://jqueryui.com/about)
 * Dual licensed under the MIT (MIT-LICENSE.txt)
 * and GPL (GPL-LICENSE.txt) licenses.
 *
 * http://docs.jquery.com/UI
 * https://github.com/fnagel/jquery-ui/wiki/Selectmenu
 */

(function($) {

$.widget("ui.selectmenu", {
    getter: "value",
    version: "1.8",
    eventPrefix: "selectmenu",
    options: {
        transferClasses: true,
        typeAhead: "sequential",
        style: 'dropdown',
        positionOptions: {
            my: "left top",
            at: "left bottom",
            offset: null
        },
        width: null,
        menuWidth: null,
        handleWidth: 26,
        maxHeight: null,
        icons: null,
        format: null,
        bgImage: function() {},
        wrapperElement: ""
    },

    _create: function() {
        var self = this, o = this.options;

        // set a default id value, generate a new random one if not set by developer
        var selectmenuId = this.element.attr('id') || 'ui-selectmenu-' + Math.random().toString(16).slice(2, 10);

        // quick array of button and menu id's
        this.ids = [ selectmenuId + '-button', selectmenuId + '-menu' ];

        // define safe mouseup for future toggling
        this._safemouseup = true;

        // FIXME temp workaround for IE
        if ($.browser.msie) o.typeAhead = "";

        // create menu button wrapper
        this.newelement = $('<a class="' + this.widgetBaseClass + ' ui-widget ui-state-default ui-corner-all" id="' + this.ids[0] + '" role="button" href="#" tabindex="0" aria-haspopup="true" aria-owns="' + this.ids[1] + '"></a>')
            .insertAfter(this.element);
        this.newelement.wrap(o.wrapperElement);

        // transfer tabindex
        var tabindex = this.element.attr('tabindex');
        if (tabindex) {
            this.newelement.attr('tabindex', tabindex);
        }

        // save reference to select in data for ease in calling methods
        this.newelement.data('selectelement', this.element);

        // menu icon
        this.selectmenuIcon = $('<span class="' + this.widgetBaseClass + '-icon ui-icon"></span>')
            .prependTo(this.newelement);

        // append status span to button
        this.newelement.prepend('<span class="' + self.widgetBaseClass + '-status" />');

        // make associated form label trigger focus
        $('label[for="' + this.element.attr('id') + '"]')
            .attr('for', this.ids[0])
            .bind('click.selectmenu', function() {
                self.newelement[0].focus();
                return false;
            });

        // click toggle for menu visibility
        this.newelement
            .bind('mousedown.selectmenu', function(event) {
                self._toggle(event, true);
                // make sure a click won't open/close instantly
                if (o.style == "popup") {
                    self._safemouseup = false;
                    setTimeout(function() { self._safemouseup = true; }, 300);
                }
                return false;
            })
            .bind('click.selectmenu', function() {
                return false;
            })
            .bind("keydown.selectmenu", function(event) {
                var ret = false;
                switch (event.keyCode) {
                    case $.ui.keyCode.ENTER:
                        ret = true;
                        break;
                    case $.ui.keyCode.SPACE:
                        self._toggle(event);
                        break;
                    case $.ui.keyCode.UP:
                        if (event.altKey) {
                            self.open(event);
                        } else {
                            self._moveSelection(-1);
                        }
                        break;
                    case $.ui.keyCode.DOWN:
                        if (event.altKey) {
                            self.open(event);
                        } else {
                            self._moveSelection(1);
                        }
                        break;
                    case $.ui.keyCode.LEFT:
                        self._moveSelection(-1);
                        break;
                    case $.ui.keyCode.RIGHT:
                        self._moveSelection(1);
                        break;
                    case $.ui.keyCode.TAB:
                        ret = true;
                        break;
                    default:
                        ret = true;
                        self._typeAhead(event.keyCode, 'mouseup');
                        break;
                }
                return ret;
            })
            .bind('mouseover.selectmenu focus.selectmenu', function() {
                if (!o.disabled) {
                    $(this).addClass(self.widgetBaseClass + '-focus ui-state-hover');
                }
            })
            .bind('mouseout.selectmenu blur.selectmenu', function() {
                if (!o.disabled) {
                    $(this).removeClass(self.widgetBaseClass + '-focus ui-state-hover');
                }
            });

        // document click closes menu
        $(document).bind("mousedown.selectmenu", function(event) {
            self.close(event);
        });

        // change event on original selectmenu
        this.element
            .bind("click.selectmenu", function() {
                self._refreshValue();
            })
            // FIXME: newelement can be null under unclear circumstances in IE8
            // TODO not sure if this is still a problem (fnagel 20.03.11)
            .bind("focus.selectmenu", function() {
                if (self.newelement) {
                    self.newelement[0].focus();
                }
            });

        // original selectmenu width
        var selectWidth = this.element.width();

        // set menu button width
        this.newelement.width(o.width ? o.width : selectWidth);

        // hide original selectmenu element
        this.element.hide();

        // create menu portion, append to body
        this.list = $('<ul class="' + self.widgetBaseClass + '-menu ui-widget ui-widget-content" aria-hidden="true" role="listbox" aria-labelledby="' + this.ids[0] + '" id="' + this.ids[1] + '"></ul>').appendTo('body');
        this.list.wrap(o.wrapperElement);

        // transfer menu click to menu button
        this.list
            .bind("keydown.selectmenu", function(event) {
                var ret = false;
                switch (event.keyCode) {
                    case $.ui.keyCode.UP:
                        if (event.altKey) {
                            self.close(event, true);
                        } else {
                            self._moveFocus(-1);
                        }
                        break;
                    case $.ui.keyCode.DOWN:
                        if (event.altKey) {
                            self.close(event, true);
                        } else {
                            self._moveFocus(1);
                        }
                        break;
                    case $.ui.keyCode.LEFT:
                        self._moveFocus(-1);
                        break;
                    case $.ui.keyCode.RIGHT:
                        self._moveFocus(1);
                        break;
                    case $.ui.keyCode.HOME:
                        self._moveFocus(':first');
                        break;
                    case $.ui.keyCode.PAGE_UP:
                        self._scrollPage('up');
                        break;
                    case $.ui.keyCode.PAGE_DOWN:
                        self._scrollPage('down');
                        break;
                    case $.ui.keyCode.END:
                        self._moveFocus(':last');
                        break;
                    case $.ui.keyCode.ENTER:
                    case $.ui.keyCode.SPACE:
                        self.close(event, true);
                        $(event.target).parents('li:eq(0)').trigger('mouseup');
                        break;
                    case $.ui.keyCode.TAB:
                        ret = true;
                        self.close(event, true);
                        $(event.target).parents('li:eq(0)').trigger('mouseup');
                        break;
                    case $.ui.keyCode.ESCAPE:
                        self.close(event, true);
                        break;
                    default:
                        ret = true;
                        self._typeAhead(event.keyCode,'focus');
                        break;
                }
                return ret;
            });

        // needed when window is resized
        $(window).bind( "resize.selectmenu", $.proxy( self._refreshPosition, this ) );
    },

    _init: function() {
        var self = this, o = this.options;

        // serialize selectmenu element options
        var selectOptionData = [];
        this.element
            .find('option')
            .each(function() {
                selectOptionData.push({
                    value: $(this).attr('value'),
                    text: self._formatText($(this).text()),
                    selected: $(this).attr('selected'),
                    disabled: $(this).attr('disabled'),
                    classes: $(this).attr('class'),
                    typeahead: $(this).attr('typeahead'),
                    parentOptGroup: $(this).parent('optgroup'),
                    bgImage: o.bgImage.call($(this))
                });
            });

        // active state class is only used in popup style
        var activeClass = (self.options.style == "popup") ? " ui-state-active" : "";

        // empty list so we can refresh the selectmenu via selectmenu()
        this.list.html("");

        // write li's
        for (var i = 0; i < selectOptionData.length; i++) {
                var thisLi = $('<li role="presentation"' + (selectOptionData[i].disabled ? ' class="' + this.namespace + '-state-disabled' + '"' : '' ) + '><a href="#" tabindex="-1" role="option"' + (selectOptionData[i].disabled ? ' aria-disabled="true"' : '' ) + ' aria-selected="false"' + (selectOptionData[i].typeahead ? ' typeahead="' + selectOptionData[i].typeahead + '"' : '' ) + '>'+ selectOptionData[i].text +'</a></li>')
                .data('index', i)
                .addClass(selectOptionData[i].classes)
                .data('optionClasses', selectOptionData[i].classes || '')
                .bind("mouseup.selectmenu", function(event) {
                    if (self._safemouseup && !self._disabled(event.currentTarget) && !self._disabled($( event.currentTarget ).parents( "ul>li." + self.widgetBaseClass + "-group " )) ) {
                        var changed = $(this).data('index') != self._selectedIndex();
                        self.index($(this).data('index'));
                        self.select(event);
                        if (changed) {
                            self.change(event);
                        }
                        self.close(event, true);
                    }
                    return false;
                })
                .bind("click.selectmenu", function() {
                    return false;
                })
                .bind('mouseover.selectmenu focus.selectmenu', function(e) {
                    // no hover if diabled
                    if (!$(e.currentTarget).hasClass(self.namespace + '-state-disabled')) {
                        self._selectedOptionLi().addClass(activeClass);
                        self._focusedOptionLi().removeClass(self.widgetBaseClass + '-item-focus ui-state-hover');
                        $(this).removeClass('ui-state-active').addClass(self.widgetBaseClass + '-item-focus ui-state-hover');
                    }
                })
                .bind('mouseout.selectmenu blur.selectmenu', function() {
                    if ($(this).is(self._selectedOptionLi().selector)) {
                        $(this).addClass(activeClass);
                    }
                    $(this).removeClass(self.widgetBaseClass + '-item-focus ui-state-hover');
                });

            // optgroup or not...
            if ( selectOptionData[i].parentOptGroup.length ) {
                var optGroupName = self.widgetBaseClass + '-group-' + this.element.find( 'optgroup' ).index( selectOptionData[i].parentOptGroup );
                if (this.list.find( 'li.' + optGroupName ).length ) {
                    this.list.find( 'li.' + optGroupName + ':last ul' ).append( thisLi );
                } else {
                    $(' <li role="presentation" class="' + self.widgetBaseClass + '-group ' + optGroupName + (selectOptionData[i].parentOptGroup.attr("disabled") ? ' ' + this.namespace + '-state-disabled" aria-disabled="true"' : '"' ) + '><span class="' + self.widgetBaseClass + '-group-label">' + selectOptionData[i].parentOptGroup.attr('label') + '</span><ul></ul></li> ')
                        .appendTo( this.list )
                        .find( 'ul' )
                        .append( thisLi );
                }
            } else {
                thisLi.appendTo(this.list);
            }

            // this allows for using the scrollbar in an overflowed list
            this.list.bind('mousedown.selectmenu mouseup.selectmenu', function() { return false; });

            // append icon if option is specified
            if (o.icons) {
                for (var j in o.icons) {
                    if (thisLi.is(o.icons[j].find)) {
                        thisLi
                            .data('optionClasses', selectOptionData[i].classes + ' ' + self.widgetBaseClass + '-hasIcon')
                            .addClass(self.widgetBaseClass + '-hasIcon');
                        var iconClass = o.icons[j].icon || "";
                        thisLi
                            .find('a:eq(0)')
                            .prepend('<span class="' + self.widgetBaseClass + '-item-icon ui-icon ' + iconClass + '"></span>');
                        if (selectOptionData[i].bgImage) {
                            thisLi.find('span').css('background-image', selectOptionData[i].bgImage);
                        }
                    }
                }
            }
        }

        // we need to set and unset the CSS classes for dropdown and popup style
        var isDropDown = (o.style == 'dropdown');
        this.newelement
            .toggleClass(self.widgetBaseClass + "-dropdown", isDropDown)
            .toggleClass(self.widgetBaseClass + "-popup", !isDropDown);
        this.list
            .toggleClass(self.widgetBaseClass + "-menu-dropdown ui-corner-bottom", isDropDown)
            .toggleClass(self.widgetBaseClass + "-menu-popup ui-corner-all", !isDropDown)
            // add corners to top and bottom menu items
            .find('li:first')
            .toggleClass("ui-corner-top", !isDropDown)
            .end().find('li:last')
            .addClass("ui-corner-bottom");
        this.selectmenuIcon
            .toggleClass('ui-icon-triangle-1-s', isDropDown)
            .toggleClass('ui-icon-triangle-2-n-s', !isDropDown);

        // transfer classes to selectmenu and list
        if (o.transferClasses) {
            var transferClasses = this.element.attr('class') || '';
            this.newelement.add(this.list).addClass(transferClasses);
        }

        // original selectmenu width
        var selectWidth = this.element.width();

        // set menu width to either menuWidth option value, width option value, or select width
        if (o.style == 'dropdown') {
            this.list.width(o.menuWidth ? o.menuWidth : (o.width ? o.width : selectWidth));
        } else {
            this.list.width(o.menuWidth ? o.menuWidth : (o.width ? o.width - o.handleWidth : selectWidth - o.handleWidth));
        }

        // calculate default max height
        if (o.maxHeight) {
            // set max height from option
            if (o.maxHeight < this.list.height()) {
                this.list.height(o.maxHeight);
            }
        } else {
            if (!o.format && ($(window).height() / 3) < this.list.height()) {
                o.maxHeight = $(window).height() / 3;
                this.list.height(o.maxHeight);
            }
        }

        // save reference to actionable li's (not group label li's)
        this._optionLis = this.list.find('li:not(.' + self.widgetBaseClass + '-group)');

        // transfer disabled state
        if (this.element.attr('disabled') === true) {
            this.disable();
        }

        // update value
        this.index(this._selectedIndex());

        // needed when selectmenu is placed at the very bottom / top of the page
        window.setTimeout(function() {
            self._refreshPosition();
        }, 200);
    },

    destroy: function() {
        this.element.removeData( this.widgetName )
            .removeClass( this.widgetBaseClass + '-disabled' + ' ' + this.namespace + '-state-disabled' )
            .removeAttr( 'aria-disabled' )
            .unbind( ".selectmenu" );

        $( window ).unbind( ".selectmenu" );
        $( document ).unbind( ".selectmenu" );

        // unbind click on label, reset its for attr
        $( 'label[for=' + this.newelement.attr('id') + ']' )
            .attr( 'for', this.element.attr( 'id' ) )
            .unbind( '.selectmenu' );

        if ( this.options.wrapperElement ) {
            this.newelement.find( this.options.wrapperElement ).remove();
            this.list.find( this.options.wrapperElement ).remove();
        } else {
            this.newelement.remove();
            this.list.remove();
        }
        this.element.show();

        // call widget destroy function
        $.Widget.prototype.destroy.apply(this, arguments);
    },

    _typeAhead: function(code, eventType){
        var self = this, focusFound = false, C = String.fromCharCode(code);
        c = C.toLowerCase();

        if (self.options.typeAhead == 'sequential') {
            // clear the timeout so we can use _prevChar
            window.clearTimeout('ui.selectmenu-' + self.selectmenuId);

            // define our find var
            var find = typeof(self._prevChar) == 'undefined' ? '' : self._prevChar.join('');

            function focusOptSeq(elem, ind, c){
                focusFound = true;
                $(elem).trigger(eventType);
                typeof(self._prevChar) == 'undefined' ? self._prevChar = [c] : self._prevChar[self._prevChar.length] = c;
            }
            this.list.find('li a').each(function(i) {
                if (!focusFound) {
                    // allow the typeahead attribute on the option tag for a more specific lookup
                    var thisText = $(this).attr('typeahead') || $(this).text();
                    if (thisText.indexOf(find+C) == 0) {
                        focusOptSeq(this,i, C)
                    } else if (thisText.indexOf(find+c) == 0) {
                        focusOptSeq(this,i,c)
                    }
                }
            });

            // if we didnt find it clear the prevChar
            // if (!focusFound) {
                //self._prevChar = undefined
            // }

            // set a 1 second timeout for sequenctial typeahead
            //      keep this set even if we have no matches so it doesnt typeahead somewhere else
            window.setTimeout(function(el) {
                el._prevChar = undefined;
            }, 1000, self);

        } else {
            //define self._prevChar if needed
            if (!self._prevChar){ self._prevChar = ['',0]; }

            var focusFound = false;
            function focusOpt(elem, ind){
                focusFound = true;
                $(elem).trigger(eventType);
                self._prevChar[1] = ind;
            }
            this.list.find('li a').each(function(i){
                if(!focusFound){
                    var thisText = $(this).text();
                    if( thisText.indexOf(C) == 0 || thisText.indexOf(c) == 0){
                            if(self._prevChar[0] == C){
                                if(self._prevChar[1] < i){ focusOpt(this,i); }
                            }
                            else{ focusOpt(this,i); }
                    }
                }
            });
            this._prevChar[0] = C;
        }
    },

    // returns some usefull information, called by callbacks only
    _uiHash: function() {
        var index = this.index();
        return {
            index: index,
            option: $("option", this.element).get(index),
            value: this.element[0].value
        };
    },

    open: function(event) {
        var self = this;
        if ( this.newelement.attr("aria-disabled") != 'true' ) {
            // TODO: seems to be useless
            // this._refreshPosition();
            this._closeOthers(event);
            this.newelement
                .addClass('ui-state-active');
            if (self.options.wrapperElement) {
                this.list.parent().appendTo('body');
            } else {
                this.list.appendTo('body');
            }

            this.list.addClass(self.widgetBaseClass + '-open').attr('aria-hidden', false);
            // FIX IE: Refreshing position before focusing the element, prevents IE from scrolling to the focused element before it is in position.
            this._refreshPosition();
            this.list.find('li:not(.' + self.widgetBaseClass + '-group):eq(' + this._selectedIndex() + ') a')[0].focus();
            if ( this.options.style == "dropdown" ) {
                this.newelement.removeClass('ui-corner-all').addClass('ui-corner-top');
            }
            this._trigger("open", event, this._uiHash());
        }
    },

    close: function(event, retainFocus) {
        if ( this.newelement.is('.ui-state-active') ) {
            this.newelement
                .removeClass('ui-state-active');
            this.list
                .attr('aria-hidden', true)
                .removeClass(this.widgetBaseClass + '-open');
            if ( this.options.style == "dropdown" ) {
                this.newelement.removeClass('ui-corner-top').addClass('ui-corner-all');
            }
            if ( retainFocus ) {
                this.newelement.focus();
            }
            this._trigger("close", event, this._uiHash());
        }
    },

    change: function(event) {
        this.element.trigger("change");
        this._trigger("change", event, this._uiHash());
    },

    select: function(event) {
        this._trigger("select", event, this._uiHash());
    },

    _closeOthers: function(event) {
        $('.' + this.widgetBaseClass + '.ui-state-active').not(this.newelement).each(function() {
            $(this).data('selectelement').selectmenu('close', event);
        });
        $('.' + this.widgetBaseClass + '.ui-state-hover').trigger('mouseout');
    },

    _toggle: function(event, retainFocus) {
        if ( this.list.is('.' + this.widgetBaseClass + '-open') ) {
            this.close(event, retainFocus);
        } else {
            this.open(event);
        }
    },

    _formatText: function(text) {
        return (this.options.format ? this.options.format(text) : text);
    },

    _selectedIndex: function() {
        return this.element[0].selectedIndex;
    },

    _selectedOptionLi: function() {
        return this._optionLis.eq(this._selectedIndex());
    },

    _focusedOptionLi: function() {
        return this.list.find('.' + this.widgetBaseClass + '-item-focus');
    },

    _moveSelection: function(amt) {
        var currIndex = parseInt(this._selectedOptionLi().data('index'), 10);
        var newIndex = currIndex + amt;
        // do not loop when using up key
        if (newIndex >= 0 )  return this._optionLis.eq(newIndex).trigger('mouseup');
    },

    _moveFocus: function(amt) {
        if (!isNaN(amt)) {
            var currIndex = parseInt(this._focusedOptionLi().data('index') || 0, 10);
            var newIndex = currIndex + amt;
        }
        else {
            var newIndex = parseInt(this._optionLis.filter(amt).data('index'), 10);
        }

        if (newIndex < 0) {
            newIndex = 0;
        }
        if (newIndex > this._optionLis.size() - 1) {
            newIndex = this._optionLis.size() - 1;
        }

        var activeID = this.widgetBaseClass + '-item-' + Math.round(Math.random() * 1000);

        this._focusedOptionLi().find('a:eq(0)').attr('id', '');

        if (this._optionLis.eq(newIndex).hasClass( this.namespace + '-state-disabled' )) {
            // if option at newIndex is disabled, call _moveFocus, incrementing amt by one
            (amt > 0) ? amt++ : amt--;
            this._moveFocus(amt, newIndex);
        } else {
            this._optionLis.eq(newIndex).find('a:eq(0)').attr('id',activeID).focus();
        }

        this.list.attr('aria-activedescendant', activeID);
    },

    _scrollPage: function(direction) {
        var numPerPage = Math.floor(this.list.outerHeight() / this.list.find('li:first').outerHeight());
        numPerPage = (direction == 'up' ? -numPerPage : numPerPage);
        this._moveFocus(numPerPage);
    },

    _setOption: function(key, value) {
        this.options[key] = value;
        // set
        if (key == 'disabled') {
            this.close();
            this.element
                .add(this.newelement)
                .add(this.list)[value ? 'addClass' : 'removeClass'](
                    this.widgetBaseClass + '-disabled' + ' ' +
                    this.namespace + '-state-disabled')
                .attr("aria-disabled", value);
        }
    },

    disable: function(index, type){
            // if options is not provided, call the parents disable function
            if ( typeof( index ) == 'undefined' ) {
                this._setOption( 'disabled', true );
            } else {
                if ( type == "optgroup" ) {
                    this._disableOptgroup(index);
                } else {
                    this._disableOption(index);
                }
            }
    },

    enable: function(index, type) {
            // if options is not provided, call the parents enable function
            if ( typeof( index ) == 'undefined' ) {
                this._setOption('disabled', false);
            } else {
                if ( type == "optgroup" ) {
                    this._enableOptgroup(index);
                } else {
                    this._enableOption(index);
                }
            }
    },

    _disabled: function(elem) {
            return $(elem).hasClass( this.namespace + '-state-disabled' );
    },


    _disableOption: function(index) {
            var optionElem = this._optionLis.eq(index);
            if (optionElem) {
                optionElem.addClass(this.namespace + '-state-disabled')
                    .find("a").attr("aria-disabled", true);
                this.element.find("option").eq(index).attr("disabled", "disabled");
            }
    },

    _enableOption: function(index) {
            var optionElem = this._optionLis.eq(index);
            if (optionElem) {
                optionElem.removeClass( this.namespace + '-state-disabled' )
                    .find("a").attr("aria-disabled", false);
                this.element.find("option").eq(index).removeAttr("disabled");
            }
    },

    _disableOptgroup: function(index) {
            var optGroupElem = this.list.find( 'li.' + this.widgetBaseClass + '-group-' + index );
            if (optGroupElem) {
                optGroupElem.addClass(this.namespace + '-state-disabled')
                    .attr("aria-disabled", true);
                this.element.find("optgroup").eq(index).attr("disabled", "disabled");
            }
    },

    _enableOptgroup: function(index) {
            var optGroupElem = this.list.find( 'li.' + this.widgetBaseClass + '-group-' + index );
            if (optGroupElem) {
                optGroupElem.removeClass(this.namespace + '-state-disabled')
                    .attr("aria-disabled", false);
                this.element.find("optgroup").eq(index).removeAttr("disabled");
            }
    },

    index: function(newValue) {
        if (arguments.length) {
            if (!this._disabled($(this._optionLis[newValue]))) {
                this.element[0].selectedIndex = newValue;
                this._refreshValue();
            } else {
                return false;
            }
        } else {
            return this._selectedIndex();
        }
    },

    value: function(newValue) {
        if (arguments.length) {
            this.element[0].value = newValue;
            this._refreshValue();
        } else {
            return this.element[0].value;
        }
    },

    _refreshValue: function() {
        var activeClass = (this.options.style == "popup") ? " ui-state-active" : "";
        var activeID = this.widgetBaseClass + '-item-' + Math.round(Math.random() * 1000);
        // deselect previous
        this.list
            .find('.' + this.widgetBaseClass + '-item-selected')
            .removeClass(this.widgetBaseClass + "-item-selected" + activeClass)
            .find('a')
            .attr('aria-selected', 'false')
            .attr('id', '');
        // select new
        this._selectedOptionLi()
            .addClass(this.widgetBaseClass + "-item-selected" + activeClass)
            .find('a')
            .attr('aria-selected', 'true')
            .attr('id', activeID);

        // toggle any class brought in from option
        var currentOptionClasses = (this.newelement.data('optionClasses') ? this.newelement.data('optionClasses') : "");
        var newOptionClasses = (this._selectedOptionLi().data('optionClasses') ? this._selectedOptionLi().data('optionClasses') : "");
        this.newelement
            .removeClass(currentOptionClasses)
            .data('optionClasses', newOptionClasses)
            .addClass( newOptionClasses )
            .find('.' + this.widgetBaseClass + '-status')
            .html(
                this._selectedOptionLi()
                    .find('a:eq(0)')
                    .html()
            );

        this.list.attr('aria-activedescendant', activeID);
    },

    _refreshPosition: function() {
        var o = this.options;
        // if its a native pop-up we need to calculate the position of the selected li
        if (o.style == "popup" && !o.positionOptions.offset) {
            var selected = this._selectedOptionLi();
            var _offset = "0 -" + (selected.outerHeight() + selected.offset().top - this.list.offset().top);
        }
        this.list
            .css({
                zIndex: this.element.zIndex()
            })
            .position({
                // set options for position plugin
                of: o.positionOptions.of || this.newelement,
                my: o.positionOptions.my,
                at: o.positionOptions.at,
                offset: o.positionOptions.offset || _offset
            });
    }
});

})(jQuery);
