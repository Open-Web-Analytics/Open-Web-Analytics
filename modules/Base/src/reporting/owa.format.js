/**
 * The reporting OWA namespace: settings, strings (OWA.l), the number, date
 * and text formatters in OWA.util, and OWA.onWidthChange. The other reporting modules import it and extend it.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */

// jQuery is imported explicitly (was supplied by webpack.ProvidePlugin before the
// ESM renovation). The 'jquery' package is CommonJS, so webpack's interop makes the
// namespace import the callable jQuery function -- same pattern as the tracker files.
import * as jQuery from 'jquery';

var OWA = {

    items: {},

    /*
     * The series colours, shared by every chart that draws more than one thing.
     *
     * ONE list, because the charts are read together: a report shows traffic
     * sources as a pie and the same sources as lines over time, and organic
     * search being orange in one and olive in the other makes the reader do
     * work that the colour was supposed to save them.
     *
     * The first four are the pie's own, in its own order, so every pie already
     * on a screen keeps the colours it had. The rest are what the pie never
     * had: it draws up to numSlices plus an "others" -- six -- from a list of
     * four, so its fifth and sixth slices repeated the first and second.
     *
     * Ten, which is one more than a trend can need: six broken-out lines plus
     * an "Other" is seven, and the total is coloured separately. Past ten a
     * chart is repeating colours because it is drawing too much, and the answer
     * is the cap rather than an eleventh shade nobody can tell from the third.
     */
    chartColors: [
        '#6BAED6', '#FD8D3C', '#dba255', '#919733', '#c0504d',
        '#7a5195', '#2e8b57', '#b5651d', '#bc5090', '#5f9ea0'
    ],

    config: {
        // Wire namespace: cookie names, and anything shared with the
        // tracker. OWA's own links carry no prefix.
        ns: 'owa_',
        baseUrl: '',
        debug: false
    },

    // depricated
    setSetting: function(name, value) {
        return this.setOption(name, value);
    },
    // depricated
    getSetting: function(name) {
        return this.getOption(name);
    },
    
    setOption: function(name, value) {
        this.config[name] = value;
    },
    
    getOption: function(name) {
        return this.config[name];
    },
    
    /*
     * A user-facing string in the locale in use, keyed by its English, as
     * CoreAPI::t() is on the server (see Core\Strings). Write the English as a
     * string literal: `cmd=strings-extract` catalogues OWA.l() and OWA.ln()
     * calls from modules/<module>/src/. A page in a translated locale sets
     * OWA.config.strings (key => translation) and OWA.config.plural_rule; with
     * neither, the English is the answer. Fill in placeholders with
     * OWA.util.sprintf(), which reads PHP's %s, %d and %1$s:
     *
     *   OWA.util.sprintf( OWA.l( '%s over time' ), label )
     */
    l: function ( text, context ) {

        return OWA.translate( text, context );
    },

    /*
     * A counted message: the form for n, as CoreAPI::tn().
     *
     *   OWA.util.sprintf( OWA.ln( '%d row', '%d rows', n ), n )
     */
    ln: function ( singular, plural, n, context ) {

        return OWA.translatePlural( singular, plural, n, context );
    },

    /** OWA.l() under a name the extractor does not read, for a string given rather than written. */
    translate: function ( text, context ) {

        var t = OWA.stringFor( text, context );

        return typeof t === 'string' ? t : String( text );
    },

    /** OWA.ln() under a name the extractor does not read. */
    translatePlural: function ( singular, plural, n, context ) {

        var forms = OWA.stringFor( singular, context );

        if ( Array.isArray( forms ) ) {

            var form = forms[ OWA.pluralIndex( n, OWA.config.plural_rule ) ];

            if ( typeof form === 'string' && form !== '' ) {
                return form;
            }
        }

        return OWA.pluralIndex( n, 'english' ) === 0 ? String( singular ) : String( plural );
    },

    /** The translation of a key, as the server sent it; undefined when there is none. */
    stringFor: function ( text, context ) {

        var map = OWA.config.strings;
        var key = context ? context + '\x04' + text : String( text );

        return map && Object.prototype.hasOwnProperty.call( map, key ) ? map[ key ] : undefined;
    },

    /*
     * Which form a count takes under a plural rule: Strings::pluralIndex() on
     * the server, which documents the rules. The count is its absolute value,
     * truncated to an integer.
     */
    pluralIndex: function ( n, rule ) {

        n = Math.abs( Math.trunc( Number( n ) || 0 ) );

        var few = n % 10 >= 2 && n % 10 <= 4 && ( n % 100 < 10 || n % 100 >= 20 );

        switch ( rule ) {

            case 'none':
                return 0;

            case 'french':
                return n > 1 ? 1 : 0;

            case 'russian':
                return n % 10 === 1 && n % 100 !== 11 ? 0 : ( few ? 1 : 2 );

            case 'polish':
                return n === 1 ? 0 : ( few ? 1 : 2 );

            case 'czech':
                return n === 1 ? 0 : ( n >= 2 && n <= 4 ? 1 : 2 );

            default:
                return n === 1 ? 0 : 1;
        }
    },
    
    /*
     * The installation's locale as a language tag (OWA_LOCALE, sent as
     * OWA.config.locale), for Intl: dates, numbers and the first day of the
     * week follow the install's language, as its strings do. Undefined -- the
     * browser's own -- when none is sent or Intl does not know the tag.
     */
    locale: function () {

        var tag = OWA.config.locale;

        if ( typeof tag !== 'string' || tag === '' ) {

            return undefined;
        }

        try {

            return Intl.getCanonicalLocales( tag )[0];

        } catch ( e ) {

            return undefined;
        }
    },

    debug: function() {
        
        var debugging = OWA.getSetting('debug') || false; // or true
        
        if ( debugging ) {
        
            if( window.console ) {
                
                if (console.log.apply) {
                
                    if (window.console.firebug) { 
                         console.log.apply(this, arguments);
                    } else {
                        console.log.apply(console, arguments);
                    }
                }
            }
        }
    },
    
    /**
	 * Add a new Action callback
	 *
	 * @param	tag			string	 	The tag that will be called by doAction
	 * @param	callback	function	The callback function to call
	 * @param	priority 	int			Priority of filter to apply.
	 */

}

OWA.util =  {

    
    nsAll: function(obj) {
    
        var nsObj = new Object();
        
        for(var param in obj) {  // print out the params
            if (obj.hasOwnProperty(param)) {
                nsObj[OWA.config.ns+param] = obj[param];
            }
        }
        
        return nsObj;
    },
    
    makeUrl: function(template, uri, params) {
        var url = this.sprintf(template, uri, jQuery.param(OWA.util.nsAll(params)));
        //alert(url);
        return url;
    },
    
    /**
     * Escape a value for interpolation into HTML.
     *
     * A grid formatter's return value becomes the cell's HTML, so any
     * value concatenated into one is markup unless it passes through here
     * first. Two formatters in owa.resultSetExplorer.js each carried their
     * own copy of these five replaces; this is that, shared, and now also
     * used by the formatters that were building markup without it.
     *
     * Covers both positions a value lands in: text content, and inside a
     * double-quoted attribute.
     */
    /**
     * A number in the install's locale (OWA.locale()): digits grouped, and
     * options as Intl.NumberFormat takes them.
     *
     *   OWA.util.formatNumber( 1234.5, { maximumFractionDigits: 1 } )   "1,234.5"
     */
    formatNumber : function( n, options ) {

        var locale = OWA.locale();
        var key    = String( locale ) + '|' + JSON.stringify( options || {} );
        var cache  = OWA.util.numberFormats || ( OWA.util.numberFormats = {} );

        if ( ! cache[ key ] ) {

            cache[ key ] = new Intl.NumberFormat( locale, options );
        }

        return cache[ key ].format( n );
    },

    /**
     * An Intl.DateTimeFormat in the install's locale (OWA.locale()), made once
     * per set of options. Give a timeZone: without one it is the browser's.
     */
    dateFormat : function( options ) {

        var locale = OWA.locale();
        var key    = String( locale ) + '|' + JSON.stringify( options || {} );
        var cache  = OWA.util.dateFormats || ( OWA.util.dateFormats = {} );

        if ( ! cache[ key ] ) {

            cache[ key ] = new Intl.DateTimeFormat( locale, options );
        }

        return cache[ key ];
    },

    /** A Date (or milliseconds) as the install's locale writes it, options as Intl.DateTimeFormat takes them. */
    formatDate : function( date, options ) {

        return OWA.util.dateFormat( options ).format( date );
    },

    /** A fraction as a percentage in the install's locale, to a fixed number of decimals: 0.125, 1 -> "12.5%". */
    formatPercent : function( fraction, decimals ) {

        var d = decimals === undefined ? 0 : decimals;

        return OWA.util.formatNumber( fraction, { style: 'percent', minimumFractionDigits: d, maximumFractionDigits: d } );
    },

    escapeHtml : function( value ) {

        if ( value === null || value === undefined ) {
            return '';
        }

        return String( value )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    },

    /**
     * A metric's value as text, or a dash when it has none. Printed as it
     * came, a NULL read "null" on the dashboard.
     */
    metricText : function( cell ) {

        var blank = function ( v ) { return v === null || v === undefined || v === ''; };

        if ( ! cell ) {
            return '\u2014';
        }

        if ( ! blank( cell.formatted_value ) ) {
            return String( cell.formatted_value );
        }

        return blank( cell.value ) ? '\u2014' : String( cell.value );
    },

    urlEncode : function(str) {
        // URL-encodes string  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/urlencode
        // +   original by: Philip Peterson
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: AJ
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: travc
        // +      input by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Lars Fischer
        // +      input by: Ratheous
        // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Joris
        // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
        // %          note 1: This reflects PHP 5.3/6.0+ behavior
        // %        note 2: Please be aware that this function expects to encode into UTF-8 encoded strings, as found on
        // %        note 2: pages served as UTF-8
        // *     example 1: urlencode('Kevin van Zonneveld!');
        // *     returns 1: 'Kevin+van+Zonneveld%21'
        // *     example 2: urlencode('http://kevin.vanzonneveld.net/');
        // *     returns 2: 'http%3A%2F%2Fkevin.vanzonneveld.net%2F'
        // *     example 3: urlencode('http://www.google.nl/search?q=php.js&ie=utf-8&oe=utf-8&aq=t&rls=com.ubuntu:en-US:unofficial&client=firefox-a');
        // *     returns 3: 'http%3A%2F%2Fwww.google.nl%2Fsearch%3Fq%3Dphp.js%26ie%3Dutf-8%26oe%3Dutf-8%26aq%3Dt%26rls%3Dcom.ubuntu%3Aen-US%3Aunofficial%26client%3Dfirefox-a'
        str = (str+'').toString();
        
        // Tilde should be allowed unescaped in future versions of PHP (as reflected below), but if you want to reflect current
        // PHP behavior, you would need to add ".replace(/~/g, '%7E');" to the following.
        return encodeURIComponent(str).replace(/!/g, '%21').replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\*/g, '%2A').replace(/%20/g, '+');
    
    },
    
    urldecode : function (str) {
        // Decodes URL-encoded string  
        // 
        // version: 1008.1718
        // discuss at: http://phpjs.org/functions/urldecode
        // +   original by: Philip Peterson
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: AJ
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Brett Zamir (http://brett-zamir.me)
        // +      input by: travc
        // +      input by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Lars Fischer
        // +      input by: Ratheous
        // +   improved by: Orlando
        // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
        // +      bugfixed by: Rob
        // %        note 1: info on what encoding functions to use from: http://xkr.us/articles/javascript/encode-compare/
        // %        note 2: Please be aware that this function expects to decode from UTF-8 encoded strings, as found on
        // %        note 2: pages served as UTF-8
        // *     example 1: urldecode('Kevin+van+Zonneveld%21');
        // *     returns 1: 'Kevin van Zonneveld!'
        // *     example 2: urldecode('http%3A%2F%2Fkevin.vanzonneveld.net%2F');
        // *     returns 2: 'http://kevin.vanzonneveld.net/'
        // *     example 3: urldecode('http%3A%2F%2Fwww.google.nl%2Fsearch%3Fq%3Dphp.js%26ie%3Dutf-8%26oe%3Dutf-8%26aq%3Dt%26rls%3Dcom.ubuntu%3Aen-US%3Aunofficial%26client%3Dfirefox-a');
        // *     returns 3: 'http://www.google.nl/search?q=php.js&ie=utf-8&oe=utf-8&aq=t&rls=com.ubuntu:en-US:unofficial&client=firefox-a'
        
        return decodeURIComponent(str.replace(/\+/g, '%20'));
    },
    
    // Returns true if variable is an object  
    // 
    // version: 1008.1718
    // discuss at: http://phpjs.org/functions/is_object
    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +   improved by: Legaev Andrey
    // +   improved by: Michael White (http://getsprink.com)
    // *     example 1: is_object('23');
    // *     returns 1: false
    // *     example 2: is_object({foo: 'bar'});
    // *     returns 2: true
    // *     example 3: is_object(null);
    // *     returns 3: false
      is_object : function (mixed_var) {

        if (mixed_var instanceof Array) {
            return false;
        } else {
            return (mixed_var !== null) && (typeof( mixed_var ) == 'object');
        }
    },
      
      countObjectProperties : function( obj ) {
          
        var size = 0, key;
        for (key in obj) {
            if (obj.hasOwnProperty(key)) size++;
        }
        return size;
      },
    
    sprintf : function( ) {
        // Return a formatted string  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/sprintf
        // +   original by: Ash Searle (http://hexmen.com/blog/)
        // + namespaced by: Michael White (http://getsprink.com)
        // +    tweaked by: Jack
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: Paulo Freitas
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: Brett Zamir (http://brett-zamir.me)
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // *     example 1: sprintf("%01.2f", 123.1);
        // *     returns 1: 123.10
        // *     example 2: sprintf("[%10s]", 'monkey');
        // *     returns 2: '[    monkey]'
        // *     example 3: sprintf("[%'#10s]", 'monkey');
        // *     returns 3: '[####monkey]'
        var regex = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g;
        var a = arguments, i = 0, format = a[i++];
     
        // pad()
        var pad = function (str, len, chr, leftJustify) {
            if (!chr) {chr = ' ';}
            var padding = (str.length >= len) ? '' : Array(1 + len - str.length >>> 0).join(chr);
            return leftJustify ? str + padding : padding + str;
        };
     
        // justify()
        var justify = function (value, prefix, leftJustify, minWidth, zeroPad, customPadChar) {
            var diff = minWidth - value.length;
            if (diff > 0) {
                if (leftJustify || !zeroPad) {
                    value = pad(value, minWidth, customPadChar, leftJustify);
                } else {
                    value = value.slice(0, prefix.length) + pad('', diff, '0', true) + value.slice(prefix.length);
                }
            }
            return value;
        };
     
        // formatBaseX()
        var formatBaseX = function (value, base, prefix, leftJustify, minWidth, precision, zeroPad) {
            // Note: casts negative numbers to positive ones
            var number = value >>> 0;
            prefix = prefix && number && {'2': '0b', '8': '0', '16': '0x'}[base] || '';
            value = prefix + pad(number.toString(base), precision || 0, '0', false);
            return justify(value, prefix, leftJustify, minWidth, zeroPad);
        };
     
        // formatString()
        var formatString = function (value, leftJustify, minWidth, precision, zeroPad, customPadChar) {
            if (precision != null) {
                value = value.slice(0, precision);
            }
            return justify(value, '', leftJustify, minWidth, zeroPad, customPadChar);
        };
     
        // doFormat()
        var doFormat = function (substring, valueIndex, flags, minWidth, _, precision, type) {
            var number;
            var prefix;
            var method;
            var textTransform;
            var value;
     
            if (substring == '%%') {return '%';}
     
            // parse flags
            var leftJustify = false, positivePrefix = '', zeroPad = false, prefixBaseX = false, customPadChar = ' ';
            var flagsl = flags.length;
            for (var j = 0; flags && j < flagsl; j++) {
                switch (flags.charAt(j)) {
                    case ' ': positivePrefix = ' '; break;
                    case '+': positivePrefix = '+'; break;
                    case '-': leftJustify = true; break;
                    case "'": customPadChar = flags.charAt(j+1); break;
                    case '0': zeroPad = true; break;
                    case '#': prefixBaseX = true; break;
                }
            }
     
            // parameters may be null, undefined, empty-string or real valued
            // we want to ignore null, undefined and empty-string values
            if (!minWidth) {
                minWidth = 0;
            } else if (minWidth == '*') {
                minWidth = +a[i++];
            } else if (minWidth.charAt(0) == '*') {
                minWidth = +a[minWidth.slice(1, -1)];
            } else {
                minWidth = +minWidth;
            }
     
            // Note: undocumented perl feature:
            if (minWidth < 0) {
                minWidth = -minWidth;
                leftJustify = true;
            }
     
            if (!isFinite(minWidth)) {
                throw new Error('sprintf: (minimum-)width must be finite');
            }
     
            if (!precision) {
                precision = 'fFeE'.indexOf(type) > -1 ? 6 : (type == 'd') ? 0 : undefined;
            } else if (precision == '*') {
                precision = +a[i++];
            } else if (precision.charAt(0) == '*') {
                precision = +a[precision.slice(1, -1)];
            } else {
                precision = +precision;
            }
     
            // grab value using valueIndex if required?
            value = valueIndex ? a[valueIndex.slice(0, -1)] : a[i++];
     
            switch (type) {
                case 's': return formatString(String(value), leftJustify, minWidth, precision, zeroPad, customPadChar);
                case 'c': return formatString(String.fromCharCode(+value), leftJustify, minWidth, precision, zeroPad);
                case 'b': return formatBaseX(value, 2, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
                case 'o': return formatBaseX(value, 8, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
                case 'x': return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
                case 'X': return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad).toUpperCase();
                case 'u': return formatBaseX(value, 10, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
                case 'i':
                case 'd':
                    number = parseInt(+value, 10);
                    prefix = number < 0 ? '-' : positivePrefix;
                    value = prefix + pad(String(Math.abs(number)), precision, '0', false);
                    return justify(value, prefix, leftJustify, minWidth, zeroPad);
                case 'e':
                case 'E':
                case 'f':
                case 'F':
                case 'g':
                case 'G':
                    number = +value;
                    prefix = number < 0 ? '-' : positivePrefix;
                    method = ['toExponential', 'toFixed', 'toPrecision']['efg'.indexOf(type.toLowerCase())];
                    textTransform = ['toString', 'toUpperCase']['eEfFgG'.indexOf(type) % 2];
                    value = prefix + Math.abs(number)[method](precision);
                    return justify(value, prefix, leftJustify, minWidth, zeroPad)[textTransform]();
                default: return substring;
            }
        };
     
        return format.replace(regex, doFormat);
    },
    
    in_array : function(needle, haystack, argStrict) {
        // Checks if the given value exists in the array  
        // 
        // version: 1008.1718
        // discuss at: http://phpjs.org/functions/in_array
        // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: vlado houba
        // +   input by: Billy
        // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
        // *     example 1: in_array('van', ['Kevin', 'van', 'Zonneveld']);
        // *     returns 1: true
        // *     example 2: in_array('vlado', {0: 'Kevin', vlado: 'van', 1: 'Zonneveld'});
        // *     returns 2: false
        // *     example 3: in_array(1, ['1', '2', '3']);
        // *     returns 3: true
        // *     example 3: in_array(1, ['1', '2', '3'], false);
        // *     returns 3: true
        // *     example 4: in_array(1, ['1', '2', '3'], true);
        // *     returns 4: false
        var key = '', strict = !!argStrict;
     
        if (strict) {
            for (key in haystack) {
                if (haystack[key] === needle) {
                    return true;
                }
            }
        } else {
            for (key in haystack) {
                if (haystack[key] == needle) {
                    return true;
                }
            }
        }
     
        return false;
    }
    
};

/**
 * Call back when an element's WIDTH changes, once it has settled.
 *
 * A report is laid out on a container-query grid, so a widget's width changes
 * for reasons the window never sees: another widget appearing, a tab becoming
 * visible, the grid promoting a span at a breakpoint. Everything here that has
 * to be redrawn at a new size uses this rather than a window resize handler,
 * which would miss all three.
 *
 * WHY NOT flot's own resize plugin, which is already loaded: it polls a list of
 * elements it was told about, and OWA.areaChart.setupAreaChart() REPLACES the
 * chart element on every redraw -- a metric change, a granularity change, any
 * refetch. The node flot registered is then detached, the poller reads a
 * detached node as invisible and stops watching it, and the chart never grows
 * again. Measured: shrinking 1600 -> 1000 redrew at 678px, and going back to
 * 1600 left the canvas at 678 inside a 1272px placeholder.
 *
 * Observing the CONTAINER rather than the thing being drawn is what keeps that
 * from happening again -- the container is the element the layout sizes and the
 * one thing here that is never replaced.
 *
 * INTEGER widths only, and height is deliberately ignored. Sub-pixel churn from
 * a flex layout would otherwise redraw continuously, and a redraw that changes
 * the element's own height would then be its own trigger.
 *
 * @param string   dom_id   the element to watch
 * @param function callback called with the new width
 * @return function to stop watching
 */
OWA.onWidthChange = function ( dom_id, callback ) {

    var el = document.getElementById( dom_id );

    if ( ! el ) {

        return function () {};
    }

    var last = Math.round( el.getBoundingClientRect().width );
    var timer = null;

    var settled = function () {

        var now = Math.round( el.getBoundingClientRect().width );

        if ( now === last || now === 0 ) {

            // Zero means hidden -- an inactive tab panel, say. Not a size to
            // draw at, and remembering it would make the next real width look
            // like no change at all.
            return;
        }

        last = now;

        callback( now );
    };

    /*
     * Debounced, because a drag delivers a change per frame and each one here
     * is a full re-plot or a grid re-layout. The delay is long enough to let a
     * drag finish and short enough that a maximise feels immediate.
     */
    var schedule = function () {

        if ( timer ) {

            clearTimeout( timer );
        }

        timer = setTimeout( settled, 120 );
    };

    if ( typeof ResizeObserver !== 'undefined' ) {

        var ro = new ResizeObserver( schedule );

        ro.observe( el );

        return function () {

            if ( timer ) { clearTimeout( timer ); }
            ro.disconnect();
        };
    }

    /*
     * No ResizeObserver: fall back to the window, which catches the common case
     * -- the reader resizing the window -- and misses a widget that changed
     * width on its own. Better than nothing, and nothing is what the fallback
     * has to be, because polling is what this exists to replace.
     */
    jQuery( window ).on( 'resize.owaWidth-' + dom_id, schedule );

    return function () {

        if ( timer ) { clearTimeout( timer ); }
        jQuery( window ).off( 'resize.owaWidth-' + dom_id );
    };
};

// owa.format.js defines the OWA namespace; the six augmenter modules extend it. It is
// shared two ways:
//   - export { OWA } -- the augmenters import it as a module binding (they mutate
//     the same object: OWA.report = ... etc.).
//   - window.OWA -- the report templates' inline <script> blocks (~166 references)
//     read the browser global, so it stays published here.
export { OWA };
window.OWA = OWA;