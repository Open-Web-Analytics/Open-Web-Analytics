import { OWA_instance as OWA } from './owa.js';

class Util {
	
	// uses global setting method
	static debug () {
		
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
	}
	
	// this uses a config global
    

    static setCookie( name, value, days, path, domain, secure ) {
        
        secure = Util.isHttps();
        
        var date = new Date();
        date.setTime(date.getTime()+(days*24*60*60*1000));
        
        document.cookie = name + "=" + escape (value) +
        ((days) ? "; expires=" + date.toGMTString() : "") +
        ((path) ? "; path=" + path : "") +
        ((domain) ? "; domain=" + domain : "") +
        '; SameSite=Lax' +
        ((secure) ? "; secure" : "");
    }
    
    
    static readAllCookies() {
    
        Util.debug('Reading all cookies...');
        var jar = {};
        var ca = document.cookie.split(';');
        
        if (ca) {
            Util.debug(document.cookie);
            for( var i=0;i < ca.length;i++ ) {
                
                var cat = String( ca[i] ).trim();
                var pos = cat.indexOf( '=' );

                // A fragment with no '=' is not a cookie.
                if ( pos < 1 ) {
                    continue;
                }

                var key = cat.substring( 0, pos );
                var value = cat.substring( pos + 1 );
                // create cookie jar array for that key
                // this is needed because you can have multiple cookies with the same name
                if ( ! jar.hasOwnProperty(key) ) {
                    jar[key] = [];
                }
                // add the value to the array
                jar[key].push(value);
            }
            
            Util.debug(JSON.stringify(jar));
            return jar;
        }
    }
    
    /**
     * Reads and returns values from cookies.
     *
     * NOTE: this function returns an array of values as there can be
     * more than one cookie with the same name.
     *
     * @return    array
     */
    static readCookie( name ) {
        Util.debug('Attempting to read cookie: %s', name);
        var jar = Util.readAllCookies();
        if ( jar ) {
            if ( jar.hasOwnProperty(name) ) {
                return jar[name];
            } else {
                return '';
            }
        }
    }
    
    static eraseCookie( name, domain ) {
    
        Util.debug(document.cookie);
        if ( ! domain ) {
            domain = OWA.getSetting('cookie_domain') || document.domain;
        }
        Util.debug("erasing cookie: " + name + " in domain: " +domain);
        this.setCookie(name,"",-1,"/",domain);
        // attempt to read the cookie again to see if its there under another valid domain
        var test = Util.readCookie(name);
        // if so then try the alternate domain                
        if (test) {
            
            var period = domain.substr(0,1);
            Util.debug('period: '+period);
            if (period === '.') {
                var domain2 = domain.substr(1);
                Util.debug("erasing " + name + " in domain2: " + domain2);
                this.setCookie(name,"",-2,"/", domain2);
                
                    
            } else {
                Util.debug("erasing " + name + " in domain3: " + domain);
                this.setCookie(name,"",-2,"/",domain);    
            }
        }
        
    }
    
    

    static loadCss( url, callback ){

        // Create new link Element 
        var link = document.createElement('link');  
  
        // set the attributes for link element 
        link.rel = 'stylesheet';  
      
        link.type = 'text/css'; 
      
        link.href = url;  
  
        // Get HTML head element to append  
        // link element to it  
        document.getElementsByTagName('HEAD')[0].appendChild(link); 

    }
    
    
    
    
    /**
     * Serialises a prepared param bag as an application/x-www-form-urlencoded
     * body, on exactly the same terms as the query-string path.
     *
     * KEY RAW, VALUE ENCODED -- the same asymmetry Tracker.prepareRequestDataForGet()
     * uses and for the same reason: values must be encoded or a '#', '&' or '='
     * inside one truncates or corrupts the payload, while the flattened array
     * keys (ct_line_items[0][li_sku]) rely on PHP's bracket parsing, which the
     * GET path documents as being defeated by encoded brackets.
     *
     * Matching that exactly matters because both bodies land in the same place:
     * RequestContainer merges $_GET and $_POST, and decodeRequestParams() then
     * decodes once more regardless of which one carried the event. A POST body
     * encoded differently from the query string would decode differently too.
     */
    static buildPostBody ( data ) {

        var pairs = [];

        for ( var param in data ) {

            if ( data.hasOwnProperty( param ) ) {
                pairs.push( param + '=' + encodeURIComponent( data[ param ] ) );
            }
        }

        return pairs.join( '&' );
    }

    /** A string URL-encoded for a query value or a fragment. */
    static urlEncode ( str ) {

        return encodeURIComponent( String( str ) );
    }
    
    /**
     * A URL-encoded string decoded. A '+' is a space: PHP's urlencode(), which
     * writes the overlay link, encodes a space that way, and so did earlier trackers.
     */
    static urldecode ( str ) {

        return decodeURIComponent( String( str ).replace( /\+/g, '%20' ) );
    }
    
    /**
     * The query parameters of a URL, lower-cased keys, decoded values.
     *
     * IT NOW HONOURS ITS ARGUMENT. The signature has always taken a url and the
     * body always read `location.href` regardless -- so a caller passing one got
     * the browser's parameters instead, silently. Nothing passed one until site
     * search needed to read the URL a virtual page view names rather than the
     * document's, which is where the mismatch would have shown up as the wrong
     * search term rather than none.
     *
     * @param {string} [url]  defaults to the current document's URL
     */
    static parseUrlParams ( url ) {
        
        var _GET = {};

        // A malformed escape (%E0%A4) made decodeURI throw out of every caller;
        // the raw text is kept instead.
        var safeDecode = function ( part ) {
            try {
                return decodeURI( part );
            } catch ( e ) {
                return part;
            }
        };
        var href = ( typeof url === 'string' && url )
            ? url
            : ( ( typeof location !== 'undefined' && location ) ? location.href : '' );
        for(var i,a,m,n,o,v,p=href.split(/[?&]/),l=p.length,k=1;k<l;k++)
            if( (m=p[k].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && m.length==4){
                n=safeDecode(m[1]).toLowerCase(),o=_GET,v=safeDecode(m[3]);
                if(m[2])
                    for(a=safeDecode(m[2]).replace(/\[\s*\]/g,"[-1]").split(/[\.\[\]]/),i=0;i<a.length;i++)
                        o=o[n]?o[n]:o[n]=(parseInt(a[i])==a[i])?[]:{}, n=a[i].replace(/^["\'](.*)["\']$/,"$1");
                        n!='-1'?o[n]=v:o[o.length]=v;
            }
        
        return _GET;
    }
    
    
    
    
    
    
    
    
    
    static clearState ( store_name, key ) {
        
        return OWA.clearState(store_name, key);
    }
    
    static getCookieValueFormat ( cstring ) {
	    
        var format = '';
        var check = cstring.substr(0,1);            
        if (check === '{') {
            format = 'json';
        } else {
            format = 'assoc';
        }
        
        return format;
    }
    
    static decodeCookieValue ( string ) {
        
        var format = Util.getCookieValueFormat(string);
        var value = '';
        if (format === 'json') {
            value = JSON.parse(string);
        
        } else {
            value = Util.jsonFromAssocString(string);
        }
        Util.debug('decodeCookieValue - string: %s, format: %s, value: %s', string, format, JSON.stringify(value));        
        return value;
    }
    
    static encodeJsonForCookie ( json_obj, format ) {
        
        format = format || 'assoc';
        
        if (format === 'json') {
            return JSON.stringify(json_obj);
        } else {
            return Util.assocStringFromJson(json_obj);
        }
    }
    
    static getCookieDomainHash( domain ) {
        
        // must be string
        return Util.dechex(Util.crc32(domain));
    }

    /** Whether a value is an object: not null, and not an array. */
    static is_object ( mixed_var ) {

        return mixed_var !== null && typeof mixed_var === 'object' && ! Array.isArray( mixed_var );
    }
      
    
    static jsonFromAssocString ( str, inner, outer ) {
        
        inner = inner || '=>';
        outer = outer || '|||';
        
        if (str){
        
            // includes() rather than an index test: index 0 is falsy, and a string
            // STARTING with the inner separator does contain it.
            if ( ! String( str ).includes( inner ) ) {
    
                return str;
                
            } else {
                
                var assoc = {};
                var outer_array = str.split(outer);
                for (var i = 0, n = outer_array.length; i < n; i++) {
                
                    var inside_array = outer_array[i].split(inner);
                    
                    assoc[inside_array[0]] = inside_array[1];
                }    
            }
            
            return assoc;
        }
    }
    
    static assocStringFromJson ( obj ) {
        
        var string = '';
        var i = 0;
        var count = Object.keys( obj ).length;
        
        for (var prop in obj) {
            i++;
            string += prop + '=>' + obj[prop];
            
            if (i < count) {
                string += '|||';
            }
        }
        return string;    
    
    }
    
    
    static getCurrentUnixTimestamp () {
	    
        return Math.round(new Date().getTime() / 1000);
    }
    
    
    /**
     * A numeric id: 62 random bits, always 19 digits.
     *
     * The visitor, session and recording ids. They land in signed BIGINT
     * columns -- a hard contract across this codebase -- so the value is below
     * 2^63; bit 62 is set, so it is at least 2^62 and always 19 digits, which
     * also keeps every id above Number.MAX_SAFE_INTEGER: nothing can carry one
     * as a float without it showing at once.
     *
     * NO TIMESTAMP PREFIX. With 62 random bits the expected collisions among n
     * ids are n^2 / 2^63 -- about 0.01 in ten years at 10 new visitors a
     * second. Nothing reads a creation time out of an id: first-seen and
     * session start ride every event as anchors.
     *
     * The cost is insert locality: owa_visitor_acquisition is keyed on the
     * visitor id, so its inserts do not append. One insert per new visitor,
     * on a table far smaller than owa_event_raw, whose key is a hash already.
     *
     * crypto.getRandomValues(), not Math.random(). BigInt is not used: the
     * tracker ships untranspiled and must run where it does not exist.
     */
    static generateRandomGuid () {

        var words = Util.randomWords( 4 );

        // Big-endian 16-bit words. Bit 63 clear, bit 62 set: [2^62, 2^63).
        words[0] = ( words[0] & 0x3fff ) | 0x4000;

        return Util.wordsToDecimal( words );
    }

    /** n random 16-bit words, from the platform's cryptographic source. */
    static randomWords ( n ) {

        var out = [];
        var source = ( typeof crypto !== 'undefined' && crypto.getRandomValues ) ? crypto : null;

        if ( source ) {

            var buffer = new Uint16Array( n );
            source.getRandomValues( buffer );

            for ( var i = 0; i < n; i++ ) {

                out.push( buffer[ i ] );
            }

            return out;
        }

        // Only where there is no crypto at all, which no supported browser is.
        for ( var j = 0; j < n; j++ ) {

            out.push( Math.floor( Math.random() * 65536 ) );
        }

        return out;
    }

    /**
     * Big-endian 16-bit words as a decimal string, exactly.
     *
     * Long division by 10 over the words: every intermediate is below
     * 10 * 65536, so a double never rounds.
     */
    static wordsToDecimal ( words ) {

        var w = words.slice();
        var digits = '';

        while ( w.some( function ( x ) { return x !== 0; } ) ) {

            var rem = 0;

            for ( var i = 0; i < w.length; i++ ) {

                var cur = rem * 65536 + w[ i ];
                w[ i ] = Math.floor( cur / 10 );
                rem = cur % 10;
            }

            digits = rem + digits;
        }

        return digits || '0';
    }
    
    
    /**
     * Base64, via the browser's own implementation.
     *
     * btoa handles bytes, not characters, so the string is widened to UTF-8
     * bytes first, with encodeURIComponent, which encodes a surrogate pair as one
     * character (UTF-8), not each half separately (CESU-8). Every emoji is
     * astral, so this is what carries one across a domain in an encoding other
     * tools decode.
     *
     * utf8_encode() stays, because crc32() still calls it: the cookie domain
     * hash it produces is stamped into every cookie already written, and
     * "correct" is not worth invalidating those over a domain name, which is
     * ASCII or punycode in every case that reaches it.
     */
    static base64_encode( data ) {

        if ( ! data ) {
            return data;
        }

        return btoa( unescape( encodeURIComponent( String( data ) ) ) );
    }

    static base64_decode( data ) {

        if ( ! data ) {
            return data;
        }

        var raw = atob( String( data ) );

        try {
            return decodeURIComponent( escape( raw ) );
        } catch ( e ) {
            /*
             * A token written by an older tracker. Its CESU-8 output is not valid
             * UTF-8, so the widening above throws rather than returning something
             * wrong -- and the bytes it choked on are what that tracker meant by them.
             * Tokens live for one cross-domain click, so this branch covers tokens in
             * flight during a deploy; throwing would break the link rather than
             * degrade it.
             */
            return raw;
        }
    }

    static crc32 ( str ) {
        // Calculate the crc32 polynomial of a string  
        // 
        // version: 1008.1718
        // discuss at: http://phpjs.org/functions/crc32
        // +   original by: Webtoolkit.info (http://www.webtoolkit.info/)
        // +   improved by: T0bsn
        // -    depends on: utf8_encode
        // *     example 1: crc32('Kevin van Zonneveld');
        // *     returns 1: 1249991249
        str = this.utf8_encode(str);
        var table = "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D";
     
        var crc = 0;
        var x = 0;
        var y = 0;
     
        crc = crc ^ (-1);
        for (var i = 0, iTop = str.length; i < iTop; i++) {
            y = ( crc ^ str.charCodeAt( i ) ) & 0xFF;
            x = "0x" + table.substr( y * 9, 8 );
            crc = ( crc >>> 8 ) ^ x;
        }
     
        return crc ^ (-1);
    }
    
    static utf8_encode ( argString ) {
        // Encodes an ISO-8859-1 string to UTF-8  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/utf8_encode
        // +   original by: Webtoolkit.info (http://www.webtoolkit.info/)
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: sowberry
        // +    tweaked by: Jack
        // +   bugfixed by: Onno Marsman
        // +   improved by: Yves Sucaet
        // +   bugfixed by: Onno Marsman
        // +   bugfixed by: Ulrich
        // *     example 1: utf8_encode('Kevin van Zonneveld');
        // *     returns 1: 'Kevin van Zonneveld'
        var string = (argString+''); // .replace(/\r\n/g, "\n").replace(/\r/g, "\n");
     
        var utftext = "";
        var start, end;
        var stringl = 0;
     
        start = end = 0;
        stringl = string.length;
        for (var n = 0; n < stringl; n++) {
            var c1 = string.charCodeAt(n);
            var enc = null;
     
            if (c1 < 128) {
                end++;
            } else if (c1 > 127 && c1 < 2048) {
                enc = String.fromCharCode((c1 >> 6) | 192) + String.fromCharCode((c1 & 63) | 128);
            } else {
                enc = String.fromCharCode((c1 >> 12) | 224) + String.fromCharCode(((c1 >> 6) & 63) | 128) + String.fromCharCode((c1 & 63) | 128);
            }
            if (enc !== null) {
                if (end > start) {
                    utftext += string.substring(start, end);
                }
                utftext += enc;
                start = end = n+1;
            }
        }
     
        if (end > start) {
            utftext += string.substring(start, string.length);
        }
     
        return utftext;
    }
    
    
    
    
    
    
    
    static clone ( mixed ) {
        
        var newObj = (mixed instanceof Array) ? [] : {};
        for (var i in mixed) {
            if (mixed[i] && (typeof mixed[i] == "object") ) {
                newObj[i] = Util.clone(mixed[i]);
            } else {
                newObj[i] = mixed[i];
            }
        }
        return newObj;
    }
    
    
    
    /** A 32-bit integer as unsigned hexadecimal: a negative one as its two's complement. */
    static dechex( number ) {

        return ( number >>> 0 ).toString( 16 );
    }
    
    
    /**
     * Whether this browser should be tracked at all.
     *
     * Two reasons it should not, and they are the same kind of answer: the
     * browser itself saying so. tracker-dom wraps the ENTIRE bootstrap in this,
     * so a false here means nothing loads, nothing is sent, and no decision is
     * left to the server.
     *
     * 1. A privacy signal, which is a request from the person: Do Not Track or
     *    Global Privacy Control. honorPrivacySignals = false ignores both --
     *    the tracker option of that name, read from the queue before anything
     *    runs (tracker-dom.js). When one is honoured, tracker-dom.js also erases
     *    the tracker's cookies (eraseTrackerCookies()).
     *
     * 2. WebDriver, which is a statement about the browser. A crawler that runs
     *    JavaScript is the only kind a JavaScript tracker ever sees, and it
     *    presents as ordinary Chrome -- because it IS Chrome, driven by a
     *    script -- so nothing on the server can identify it from the user
     *    agent. The WebDriver specification requires a conforming browser to
     *    report navigator.webdriver as true while under automation, and that is
     *    the only place the question can be answered.
     *
     * Only the standard flag, no fingerprinting. Plugin counts and language
     * lists are guessy, and a wrong answer here silently costs a real page
     * view; anything deliberately hiding from the flag would defeat a
     * fingerprint too.
     */
    static isBrowserTrackable ( honorPrivacySignals = true ) {

        if ( honorPrivacySignals !== false && Util.sendsPrivacySignal() ) {

            return false;
        }

        if ( navigator.webdriver === true && ! Util.automationIsAllowed() ) {

            return false;
        }
        
        return true;
    }

    /**
     * Whether the page was opened on an overlay link: its fragment has an
     * ns + 'overlay' pair (#owa_overlay.<params>), as
     * OWATracker.getAnchorParam() reads it. Whether the link's api_url is this
     * OWA server's is the tracker's to decide (checkForOverlaySession()).
     *
     * @return {boolean}
     */
    static opensOverlay () {

        var name = ( OWA.getSetting( 'ns' ) || 'owa_' ) + 'overlay';
        var hash = ( typeof location !== 'undefined' && location.hash ) ? String( location.hash ).substring( 1 ) : '';

        return hash.split( ',' ).some( function ( pair ) {

            var pieces = pair.split( '.' );

            return pieces[0] === name && !! pieces[1];
        } );
    }

    /**
     * Whether the browser asks not to be tracked: Do Not Track ("1", or the
     * legacy msDoNotTrack) or Global Privacy Control (true).
     */
    static sendsPrivacySignal () {

        var dntProperties = ['doNotTrack', 'msDoNotTrack'];

        for (var i = 0, l = dntProperties.length; i < l; i++) {

            if ( navigator[ dntProperties[i] ] && navigator[ dntProperties[i] ] == "1" ) {

                return true;
            }
        }

        return navigator.globalPrivacyControl === true;
    }

    /**
     * Expire the tracker's own cookies: the visitor store (ns + 'v'), every
     * per-site session store (ns + 's_<siteId>'), and the 's', 'b' and 'c'
     * stores an older tracker may have left. Nothing else named with the ns
     * prefix is touched.
     *
     * Run when a privacy signal is honoured (tracker-dom.js), so a browser
     * that asked not to be tracked keeps no identifier from an earlier visit.
     * The tracker has not run, so the domain it would have set them on is not
     * known: each is expired host-only and on the page's host and every
     * parent domain, which covers every domain a tracker could have used.
     */
    static eraseTrackerCookies () {

        var ns   = OWA.getSetting( 'ns' ) || 'owa_';
        var jar  = Util.readAllCookies() || {};
        var host = ( typeof location !== 'undefined' && location.hostname ) ? String( location.hostname )
            : ( ( typeof document !== 'undefined' && document.domain ) ? String( document.domain ) : '' );

        var domains = [ '' ];
        var labels  = host === '' ? [] : host.split( '.' );

        for ( var i = 0; i < labels.length; i++ ) {

            // Not the last label alone: no cookie is set on a top-level domain.
            if ( i > 0 && i === labels.length - 1 ) {
                break;
            }

            domains.push( '.' + labels.slice( i ).join( '.' ) );
        }

        Object.keys( jar ).forEach( function ( name ) {

            if ( name.indexOf( ns ) !== 0 || ! /^(v|b|c|s(_.+)?)$/.test( name.substring( ns.length ) ) ) {
                return;
            }

            domains.forEach( function ( domain ) {
                Util.setCookie( name, '', -1, '/', domain );
            } );
        } );
    }

    /**
     * Whether an automated browser has been explicitly opted back in.
     *
     * Needed because automating your own site is a legitimate thing to do --
     * end-to-end tests, synthetic monitoring, an uptime check that should
     * appear in reports.
     *
     * Opt-in rather than opt-out: the common case is a crawler, and the site
     * owner is the only one who can say otherwise.
     *
     *     window.owa_track_automated_browsers = true;
     */
    static automationIsAllowed () {

        return typeof window !== 'undefined' && window.owa_track_automated_browsers === true;
    }
    
    static isHttps() {
	    
	    return (document.location.protocol == 'https:');
    }
}

export { Util };