<? include('report_header.tpl');?>

<P><a href="<?=$this->makeLink(array('do' => 'base.kmlVisitsGeolocation'), true, $this->config['action_url']);?>">View in Google Earth</a></P>

<? if(!empty($this->config['google_maps_api_key'])):?>

<script src="http://maps.google.com/maps?file=api&amp;v=2&amp;key=<?=$this->config['google_maps_api_key'];?>" type="text/javascript"></script>
 
<table>
	<tr>
        <td valign="top">
			<div id="map" style="width: 600px; height: 480px"></div>
			<noscript>
				<b>JavaScript must be enabled in order for you to use Google Maps.</b> 
      			However, it seems JavaScript is either disabled or not supported by your browser. 
      			To view Google Maps, enable JavaScript by changing your browser options, and then 
      			try again.
			</noscript>
        </td>
        <td valign="top">
           <fieldset>
				<legend>Visitors</legend>
				<div id="side_bar" style="overflow:auto; width:300px;height:400px;"></div>
           </fieldset>
        </td>
	</tr>
</table>


    <script type="text/javascript">

    if (GBrowserIsCompatible()) {
      // this variable will collect the html which will eventualkly be placed in the side_bar
      var side_bar_html = "";
    
      // arrays to hold copies of the markers and html used by the side_bar
      // because the function closure trick doesnt work there
      var gmarkers = [];
      var htmls = [];
      var i = 0;


      // A function to create the marker and set up the event window
      function createMarker(point,name,html) {
        var marker = new GMarker(point);
        GEvent.addListener(marker, "click", function() {
          marker.openInfoWindowHtml(html);
        });
        // save the info we need to use later for the side_bar
        gmarkers[i] = marker;
        htmls[i] = html;
        // add a line to the side_bar html
        side_bar_html += '<a href="javascript:myclick(' + i + ')">' + name + '</a><br><br>';
        i++;
        return marker;
      }


      // create the map
      var map = new GMap2(document.getElementById("map"));
      map.addControl(new GLargeMapControl());
      map.addControl(new GMapTypeControl());
      map.setCenter(new GLatLng( 43.907787,-79.359741), 2);
      var bounds = new GLatLngBounds();

      // Read the data from example.xml
      var request = GXmlHttp.create();
 
      request.open("GET", "<?=$this->makeLink(array('do' => 'base.xmlVisitsGeolocation', 'rand' => rand()), true, $this->config['action_url']); ?>", true);
      request.onreadystatechange = function() {
        if (request.readyState == 4) {
          var xmlDoc = request.responseXML;
          // obtain the array of markers and loop through it
          var markers = xmlDoc.documentElement.getElementsByTagName("marker");
          
          for (var i = 0; i < markers.length; i++) {
            // obtain the attribues of each marker
            var lat = parseFloat(markers[i].getAttribute("lat"));
            var lng = parseFloat(markers[i].getAttribute("lng"));
            var point = new GLatLng(lat,lng);
            bounds.extend(point);
            var html = GXml.value(markers[i].getElementsByTagName("infowindow")[0]);
            var label = markers[i].getAttribute("label");
            // create the marker
            var marker = createMarker(point,label,html);
            map.addOverlay(marker);
          }
          // put the assembled side_bar_html contents into the side_bar div
          document.getElementById("side_bar").innerHTML = side_bar_html;
          map.setZoom(map.getBoundsZoomLevel(bounds));
          map.setCenter(bounds.getCenter());
        }
      }
      request.send(null);
    }

    else {
      alert("Sorry, the Google Maps API is not compatible with this browser");
    }
    // This Javascript is based on code provided by the
    // Blackpool Community Church Javascript Team
    // http://www.commchurch.freeserve.co.uk/   
    // http://www.econym.demon.co.uk/googlemaps/

   //window.onunload = GUnload();
   
    // This function picks up the click and opens the corresponding info window
      function myclick(i) {
      	
      	var epoint = gmarkers[i].getPoint();
      	//alert(epoint); 
      	
        gmarkers[i].openInfoWindowHtml(htmls[i]);
        //map.openInfoWindowHtml(epoint, htmls[i]);
        //map.panTo(epoint);
        
      }
    </script>
<? else:?>
<div class="error">
	You must have a Google Maps API Key to use this feature. Google provides this key for free. 
	You can get a key in <b>minutes</b> by visiting <a href="http://www.google.com/apis/maps/signup.html" target="_blank">this Google web site</a> and then 
	entering the key on the <a href="<?=$this->makeLink(array('view' => 'base.options'));?>">main OWA configuration page</a>.
</div>
<?endif;?>