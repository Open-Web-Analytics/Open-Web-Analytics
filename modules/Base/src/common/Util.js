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
        //var dhash = '';
        var jar = {};
        //var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        
        if (ca) {
            Util.debug(document.cookie);
            for( var i=0;i < ca.length;i++ ) {
                
                var cat = String( ca[i] ).trim();
                var pos = cat.indexOf( '=' );

                // A fragment with no '=' is not a cookie. The PHP strpos port
                // returned false here, and substring(0, false) is substring(0,
                // 0) -- so this used to produce an empty key holding the
                // fragment with its first character eaten.
                if ( pos < 1 ) {
                    continue;
                }

                var key = cat.substring( 0, pos );
                var value = cat.substring( pos + 1 );
                //Util.debug('key %s, value %s', key, value);
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
                //    domain = '.'+ domain
                Util.debug("erasing " + name + " in domain3: " + domain);
                this.setCookie(name,"",-2,"/",domain);    
            }
            //Util.debug("erasing " + name + " in domain: ");
            //this.setCookie(name,"",-2,"/");    
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

    static urlEncode ( str ) {
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
    
    }
    
    static urldecode ( str ) {
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
    }
    
    static parseUrlParams ( url ) {
        
        var _GET = {};
        for(var i,a,m,n,o,v,p=location.href.split(/[?&]/),l=p.length,k=1;k<l;k++)
            if( (m=p[k].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && m.length==4){
                n=decodeURI(m[1]).toLowerCase(),o=_GET,v=decodeURI(m[3]);
                if(m[2])
                    for(a=decodeURI(m[2]).replace(/\[\s*\]/g,"[-1]").split(/[\.\[\]]/),i=0;i<a.length;i++)
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
        //Util.debug('decodeCookieValue - string: %s, format: %s', string, format);        
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
    

      
    // Returns input string padded on the left or right to specified length with pad_string  
    // 
    // version: 1109.2015
    // discuss at: http://phpjs.org/functions/str_pad
    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // + namespaced by: Michael White (http://getsprink.com)
    // +      input by: Marco van Oort
    // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
    // *     example 1: str_pad('Kevin van Zonneveld', 30, '-=', 'STR_PAD_LEFT');
    // *     returns 1: '-=-=-=-=-=-Kevin van Zonneveld'
    // *     example 2: str_pad('Kevin van Zonneveld', 30, '-', 'STR_PAD_BOTH');
    // *     returns 2: '------Kevin van Zonneveld-----'
    
    /**
     * Left-pad a number with zeros to a fixed width.
     *
     * Was a wrapper over a 38-line str_pad() port of PHP's, called with
     * STR_PAD_LEFT and '0' and nothing else -- the port's other three pad
     * types, its custom pad strings and its multi-character padding were all
     * unreachable. padStart is the same operation, and it is in the language.
     */
    static zeroFill ( number, length ) {

        return String( number ).padStart( length, '0' );
    }
      
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
    static is_object ( mixed_var ) {

        if (mixed_var instanceof Array) {
            return false;
        } else {
            return (mixed_var !== null) && (typeof( mixed_var ) == 'object');
        }
    }
      
    
    static jsonFromAssocString ( str, inner, outer ) {
        
        inner = inner || '=>';
        outer = outer || '|||';
        
        if (str){
        
            // includes(), not the old strpos() port: that returned an index,
            // and index 0 is falsy, so a string STARTING with the inner
            // separator was reported as containing no separator at all.
            if ( ! String( str ).includes( inner ) ) {
    
                return str;
                
            } else {
                
                var assoc = {};
                var outer_array = str.split(outer);
                //Util.debug('outer array: %s', JSON.stringify(outer_array));
                for (var i = 0, n = outer_array.length; i < n; i++) {
                
                    var inside_array = outer_array[i].split(inner);
                    
                    assoc[inside_array[0]] = inside_array[1];
                }    
            }
            
            //Util.debug('jsonFromAssocString: ' + JSON.stringify(assoc));
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
        //Util.debug('Util.assocStringFromJson: %s', string);
        return string;    
    
    }
    
    
    // strips www. from begining of domain if present
    // otherwise returns the domain as is.
    static stripWwwFromDomain ( domain ) {
        
        var fp = domain.split('.')[0];
            
        if (fp === 'www') {
            return domain.substring(4);
        } else {
            return domain;
        }
    }
    
    static getCurrentUnixTimestamp () {
	    
        return Math.round(new Date().getTime() / 1000);
    }
    
    
    /**
     * A numeric id: 10 digits of unix seconds followed by 9 random digits.
     *
     * Takes no salt, and cannot. The result must fit a signed BIGINT -- a hard
     * contract across this codebase -- and this construction already consumes
     * 60.6 of the 63 available bits, so there is nothing to mix a salt into
     * without either taking bits from the random half or making ids
     * predictable from their inputs. Callers once passed one and it was
     * silently discarded.
     *
     * The split between the time and random halves is not worth re-tuning
     * either: coarsening the time bucket by k multiplies the ids sharing a
     * bucket by k (candidate pairs by k^2), divides the bucket count by k and
     * grows the random space by k, so k cancels and the collision rate is
     * unchanged. It is a function of the total bit budget and the arrival rate
     * alone -- roughly 0.8 expected collisions a year at 10 new visitors per
     * second. Improving on that needs a wider id, which BIGINT forbids.
     *
     * The leading timestamp is load-bearing beyond ordering: it keeps inserts
     * at the right edge of the primary key. See tests/js/GuidContract.test.js.
     */
    static generateRandomGuid () {
	    
        var time = this.getCurrentUnixTimestamp() + '';
        var random = Util.zeroFill( this.rand(0,999999) + '' , 6);
        var client = Util.zeroFill( this.rand(0,999) + '', 3);
        return time + random + client;
    }
    
    
    /**
     * Base64, via the browser's own implementation.
     *
     * Replaces a 118-line phpjs port whose UTF-8 pre-pass encoded each half of a
     * surrogate pair separately -- CESU-8, not UTF-8. Every astral-plane
     * character goes through a surrogate pair, and every emoji is astral, so a
     * custom variable or page title containing one was carried across a domain
     * in an encoding no other tool decodes. The port round-tripped its own
     * output, which is why it never looked broken from inside OWA.
     *
     * btoa handles bytes, not characters, so the string is widened to UTF-8
     * bytes first. That step is what the port was for; the difference is that
     * encodeURIComponent gets surrogate pairs right.
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
             * A token written by a tracker older than this one. Its CESU-8
             * output is not valid UTF-8, so the widening above throws rather
             * than returning something wrong -- and the bytes it choked on are
             * what that tracker meant by them.
             *
             * Returning them raw is what the old decoder did. This branch is
             * for tokens in flight during a deploy: they live for one
             * cross-domain click, so it stops mattering within minutes, but
             * throwing would break the link rather than degrade it.
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
    
    
    
    static rand ( min, max ) {
        // Returns a random number  
        // 
        // version: 1008.1718
        // discuss at: http://phpjs.org/functions/rand
        // +   original by: Leslie Hoare
        // +   bugfixed by: Onno Marsman
        // *     example 1: rand(1, 1);
        // *     returns 1: 1
        
        var argc = arguments.length;
        if (argc === 0) {
            min = 0;
            max = 2147483647;
        } else if (argc === 1) {
            throw new Error('Warning: rand() expects exactly 2 parameters, 1 given');
        }
        return Math.floor(Math.random() * (max - min + 1)) + min;
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
    
    
    
    static dechex( number ) {
	    
        // Returns a string containing a hexadecimal representation of the given number  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/dechex
        // +   original by: Philippe Baumann
        // +   bugfixed by: Onno Marsman
        // +   improved by: http://stackoverflow.com/questions/57803/how-to-convert-decimal-to-hex-in-javascript
        // +   input by: pilus
        // *     example 1: dechex(10);
        // *     returns 1: 'a'
        // *     example 2: dechex(47);
        // *     returns 2: '2f'
        // *     example 3: dechex(-1415723993);
        // *     returns 3: 'ab9dc427'
        if (number < 0) {
            number = 0xFFFFFFFF + number + 1;
        }
        return parseInt(number, 10).toString(16);
    }
    
    
    /**
     * Whether this browser should be tracked at all.
     *
     * Two reasons it should not, and they are the same kind of answer: the
     * browser itself saying so. tracker-dom wraps the ENTIRE bootstrap in this,
     * so a false here means nothing loads, nothing is sent, and no decision is
     * left to the server.
     *
     * 1. Do Not Track, which is a request from the person.
     *
     * 2. WebDriver, which is a statement about the browser. A crawler that runs
     *    JavaScript is the only kind a JavaScript tracker ever sees, and it
     *    presents as ordinary Chrome -- because it IS Chrome, driven by a
     *    script -- so nothing on the server can identify it from the user
     *    agent. The WebDriver specification requires a conforming browser to
     *    report navigator.webdriver as true while under automation, and that is
     *    the only place the question can be answered. Measured on a live
     *    installation, one such crawler made 365 requests over two days and was
     *    counted as a person.
     *
     * Only the standard flag, no fingerprinting. Plugin counts and language
     * lists are guessy, and a wrong answer here silently costs a real page
     * view; anything deliberately hiding from the flag would defeat a
     * fingerprint too.
     */
    static isBrowserTrackable () {
    
        var dntProperties = ['doNotTrack', 'msDoNotTrack'];
        
        for (var i = 0, l = dntProperties.length; i < l; i++) {
        
            if ( navigator[ dntProperties[i] ] && navigator[ dntProperties[i] ] == "1" ) {
                
                return false;
            }
        }

        if ( navigator.webdriver === true && ! Util.automationIsAllowed() ) {

            return false;
        }
        
        return true;
    }

    /**
     * Whether an automated browser has been explicitly opted back in.
     *
     * Needed because automating your own site is a legitimate thing to do --
     * end-to-end tests, synthetic monitoring, an uptime check that should
     * appear in reports. Without a way back in, this project's own e2e suite
     * would record nothing, which is a fair warning about what it would do to
     * everyone else's.
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