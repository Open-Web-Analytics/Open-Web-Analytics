
// OWA wb Javascript lib - STATIC START

if (typeof owa_site_id == 'undefined') {
	var owa_site_id = '1';
}
if (typeof owa_user_email == 'undefined') {
	var owa_user_email = '';
}
if (typeof owa_user_name == 'undefined') {
	var owa_user_name = '';
}
if (typeof owa_page_uri == 'undefined') {
	var owa_page_uri = owa_get_url();
}
if (typeof owa_page_title == 'undefined') {
	var owa_page_title = owa_get_title();
}
if (typeof owa_page_type == 'undefined') {
	var owa_page_type = '';
}
if (typeof owa_referer == 'undefined') {
	var owa_referer = owa_get_referer();
}
owa_log();
	
// Base64 encodes strings
// Taken from http://www.jan-winkler.de/hw/artikel/art_j02.htm
function owa_base64_encode(decStr) {
  var base64s = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
  var bits;
  var dual;
  var i = 0;
  var encOut = '';

  while(decStr.length >= i + 3) {
    bits = (decStr.charCodeAt(i++) & 0xff) <<16 |
           (decStr.charCodeAt(i++) & 0xff) <<8 |
            decStr.charCodeAt(i++) & 0xff;

    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
              base64s.charAt((bits & 0x0003f000) >>12) +
              base64s.charAt((bits & 0x00000fc0) >> 6) +
              base64s.charAt((bits & 0x0000003f));
  }

  if(decStr.length -i > 0 && decStr.length -i < 3) {
    dual = Boolean(decStr.length -i -1);

    bits = ((decStr.charCodeAt(i++) & 0xff) <<16) |
           (dual ? (decStr.charCodeAt(i) & 0xff) <<8 : 0);

    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
              base64s.charAt((bits & 0x0003f000) >>12) +
              (dual ? base64s.charAt((bits & 0x00000fc0) >>6) : '=') +
              '=';
  }

  return(encOut);
}

// Gets the resolution of the users screen from browser
function owa_get_resolution() {
	
	var resolution = window.screen.width + 'x' + window.screen.height + 'x' + window.screen.colorDepth + 'bit';
	return(resolution);  
}               

//Gets title of web page from browser
function owa_get_title() {
	
	var title = document.title;	
	return(title);
}

// Gets the url ro mthe address barof browser
function owa_get_url() {

	var url = owa_base64_encode(document.URL);
	return(url);
}

// Gets the referer url from browser
function owa_get_referer() {
	
	var referer = owa_base64_encode(document.referrer);
	return(referer);
}

function owa_log() {
	
	document.write(
	  '<img src="'				+ owa_url +
	  'site_id='				+ owa_site_id + '&' +
	  'page_title='				+ owa_page_title + '&' +
	  'page_uri='           	+ owa_page_uri + '&' +
	  'referer='                + owa_referer + '&' +
	  'user_email=' 			+ owa_user_email + '&' +
	  'user_name='				+ owa_user_name + '&' +
	  'page_type='				+ owa_page_type +
	  '" alt="" width="1" height="1" />'
	);		
	
}


