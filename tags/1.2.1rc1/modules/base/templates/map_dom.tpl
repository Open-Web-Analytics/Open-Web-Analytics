<?php if(!empty($this->config['google_maps_api_key'])):?> 

<div class="owa_map-container">
	<img align="bottom" src="<?php echo $this->makeImageLink('kml_feed_small.png');?>"> <a class="owa_map-type-control" maptype="earth" href="#">View in Google Earth</a><BR><BR>
	<div id="map" class="jmap" style="width: 100%; height: 500px"></div>
</div>
<script>

OWA.items['map'] = new OWA.map();
OWA.items['map'].dom_id = 'map';
<?php foreach($latest_visits as $k => $visit): ?>
<?php if (!empty($visit['host_longitude'])):?>
OWA.items['map'].markers[<?php echo $k;?>] = {pointLatLng: [<?php echo trim($visit['host_latitude']);?>, <?php echo trim($visit['host_longitude']);?>], pointHTML: '<?php echo preg_replace("/[\n\r]/", '', $this->subTemplate('report_visit_summary_balloon.tpl', array('visit' => $visit)));?>'};
<?php endif;?>
<?php endforeach;?>
OWA.items['map'].placeMarkers();

</script>
	
<?php else:?>

<div class="error">
	You must have a Google Maps API Key to use this feature. Google provides this key for free. You can get a key in <b>minutes</b> by visiting <a href="http://www.google.com/apis/maps/signup.html" target="_blank">this Google web site</a> and then entering the key on the <a href="<?php echo $this->makeLink(array('view' => 'base.options'));?>">main OWA configuration page</a>.
</div>

<?php endif;?>

