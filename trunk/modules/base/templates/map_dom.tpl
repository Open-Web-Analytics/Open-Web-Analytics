<?php if(!empty($this->config['google_maps_api_key'])):?> 

<div class="owa_map-container">
	<img align="bottom" src="<?php echo $this->makeImageLink('kml_feed_small.png');?>"> <a class="owa_map-type-control" maptype="earth" href="#">View in Google Earth</a><BR><BR>
	<div id="map" class="jmap" style="width: 100%; height: 500px"></div>
</div>
<script>

OWA.items['map'] = new OWA.map();
OWA.items['map'].dom_id = 'map';
<?php foreach($latest_visits->resultsRows as $k => $visit): ?>
<?php if (!empty($visit['host_longitude'])):?>
OWA.items['map'].markers[<?php echo $k;?>] = {pointLatLng: [<?php echo trim($visit['host_latitude']);?>, <?php echo trim($visit['host_longitude']);?>], pointHTML: '<?php echo preg_replace("/[\n\r]/", '', $this->subTemplate('report_visit_summary_balloon.tpl', array('visit' => $visit)));?>'};
<?php endif;?>
<?php endforeach;?>
OWA.items['map'].placeMarkers();

</script>
	
<?php else:?>

<div class="error">
	You must have a Google Maps API Key to use this feature. Google provides this key for free at <a href="http://www.google.com/apis/maps/signup.html" target="_blank">this Google web site</a>. Once you obtain a key enter in on the <a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile', 'site_id' => $site_id));?>">profile page for this tracked web site</a>.
</div>

<?php endif;?>

