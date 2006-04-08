// wa webbug

<script language="JavaScript" type="text/javascript">




// Taken from http://www.jan-winkler.de/hw/artikel/art_j02.htm

function base64_encode(decStr) {
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


var site_id = 2;
var resolution = window.screen.width + 'x' +
                 window.screen.height + 'x' +
                 window.screen.colorDepth + 'bit';
				 
var webbug_log_path = '/padams/wordpress-1.5.2/wp-content/plugins/wa/page.php';

document.write(
  '<img src="' + webbug_log_path + '?' +
  'site_id='              + site_id + '&' +
  'page_title='				+ document.title + '&' +
  'uri='           + base64_encode(document.URL) + '&' +
  'referer='                + base64_encode(document.referrer) + '&' +
  'add_data[]=resolution::' + resolution +
  '" alt="" width="1" height="1" />'
);

</script>
<!--<noscript><img alt="" src="/track/image.php?client_id=2" width="1" height="1" /></noscript>-->
