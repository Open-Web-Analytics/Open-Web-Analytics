<script src="http://maps.google.com/maps?file=api&amp;v=2&amp;key=<?=$this->config['google_maps_api_key'];?>" type="text/javascript"></script>
<noscript>
	<div class="error">
		<b>JavaScript must be enabled in order for you to use Google Maps.</b> However, it seems JavaScript is either disabled or not supported by your browser. To view Google Maps, enable JavaScript by changing your browser options, and then try again.
	</div>
</noscript>

<div class="owa_reportSectionContent">
	<P><a href="<?=$this->makeLink(array('do' => 'base.kmlVisitsGeolocation'), true, $this->config['action_url']);?>">View in Google Earth</a></P>
	
	<?php if(!empty($this->config['google_maps_api_key'])):?> 
	<a class="add-feed" >Add</a> 
	<a class="remove-feed" >Remove</a> 
	<div id="map" class="jmap" style="width: 100%; height: 500px"></div>
	
	<?php else:?>
	<div class="error">
		You must have a Google Maps API Key to use this feature. Google provides this key for free. 
		You can get a key in <b>minutes</b> by visiting <a href="http://www.google.com/apis/maps/signup.html" target="_blank">this Google web site</a> and then 
		entering the key on the <a href="<?=$this->makeLink(array('view' => 'base.options'));?>">main OWA configuration page</a>.
	</div>
	<?php endif;?>
</div>

<script>

jQuery(document).ready(function(){
	jQuery('#map').jmap('init', {'mapType':'map','mapZoom': 2, 'debugMode': false,'mapCenter':[8.958639, -3.162516]});
	
	jQuery('.add-feed').click(function(){
		jQuery('#map').jmap('AddFeed', {
			'feedUrl':'<?=$this->makeLink(array('do' => 'base.xmlVisitsGeolocation', 'rand' => rand()), true, $this->config['action_url']); ?>'}, function(feed, options){
			jQuery('.remove-feed').click(function(){
				jQuery('#map').jmap('RemoveFeed', feed);
			})
		});
	});
});

</script>