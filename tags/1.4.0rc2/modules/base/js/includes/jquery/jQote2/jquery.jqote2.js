/*
 * jQote2 - client-side Javascript templating engine
 * Copyright (C) 2010, aefxx
 * http://aefxx.com/
 *
 * Licensed under the DWTFYWT PUBLIC LICENSE v2
 * Copyright (C) 2004, Sam Hocevar
 *
 * Date: Sun, May 5th, 2010
 * Version: 0.9.2
 */
(function($) {
	var ARR = '[object Array]',
		FUNC = '[object Function]',
		STR = '[object String]';

    var n = 0,
		tag = '%',
	    type_of = Object.prototype.toString;

    $.fn.extend({
		jqote: function(data, t) {
			var data = type_of.call(data) === ARR ? data : [data],
				dom = '';

			this.each(function(i) {
				var f = ( fn = $.jqotecache[this.jqote] ) ? fn : $.jqotec(this, t || tag);

				for ( var j=0; j < data.length; j++ )
					dom += f.call(data[j], i, j, data, f);
			});

			return dom;
		},

		jqoteapp: function(elem, data, t) {
            var dom = $.jqote(elem, data, t);

			return this.each(function() {
				$(this).append(dom);
			});
		},

		jqotepre: function(elem, data, t) {
            var dom = $.jqote(elem, data, t);

			return this.each(function() {
				$(this).prepend(dom);
			});
		},

		jqotesub: function(elem, data, t) {
            var dom = $.jqote(elem, data, t);

			return this.each(function() {
				$(this).html(dom);
			});
		}
	});

    $.extend({
        jqote: function(elem, data, t) {
            var dom = '', fn = [], t = t || tag, type = type_of.call(elem),
                data = type_of.call(data) === ARR ? data : [data];

            if ( type === FUNC )
                    fn = [elem];

            else if ( type === ARR )
                fn = type_of.call(elem[0]) === FUNC ?
                    elem : $.map(elem, function(e) { return $.jqotec(e, t); });

            else if ( type === STR )
                fn.push( elem.indexOf('<' + t) < 0 ?
                    $.jqotec($(elem), t) : $.jqotec(elem, t));

            else fn = $.map($(elem), function(e) { return $.jqotec(e, t); });

            for ( var i=0,l=fn.length; i < l; i++ )
                for ( var j=0; j < data.length; j++ )
                    dom += fn[i].call(data[j], i, j, data, fn[i]);

            return dom;
        },

        jqotec: function(elem, t) {
            var fn, str = '', t = t || tag,
                type = type_of.call(elem),
                tmpl = ( type === STR && elem.indexOf('<' + t) >= 0 ) ?
                            elem : ( elem = ( type === STR  || elem instanceof jQuery ) ?
                                $(elem)[0] : elem ).innerHTML;

            var arr = tmpl.replace(/\s*<!\[CDATA\[\s*|\s*\]\]>\s*|[\r\n\t]/g, '')
                        .split('<'+t).join(t+'>\x1b')
                            .split(t+'>');

            for ( var i=0,l=arr.length; i < l; i++ )
                str += arr[i].charAt(0) !== '\x1b' ?
                    "out+='" + arr[i].replace(/([^\\])?(["'])/g, '$1\\$2') + "'" : (arr[i].charAt(1) === '=' ?
                        '+' + arr[i].substr(2) + ';' : ';' + arr[i].substr(1));

            fn = new Function('i, j, data, fn', 'var out="";' + str + '; return out;');

            return type_of.call(elem) === STR ?
                fn : $.jqotecache[elem.jqote = elem.jqote || n++] = fn;
        },

        jqotefn: function(elem) {
            return $.jqotecache[$(elem)[0].jqote] || false;
        },

        jqotetag: function(str) {
            tag = str;
        },

        jqotecache: []
    });
})(jQuery);
