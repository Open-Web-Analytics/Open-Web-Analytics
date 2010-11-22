<script src="http://maps.google.com/maps?file=api&amp;v=2&amp;key=<?php $this->out( owa_coreAPI::getSiteSetting($site_id, 'google_maps_api_key') );?>" type="text/javascript"></script>
<noscript>
	<div class="error">
		<b>JavaScript must be enabled in order for you to use Google Maps.</b> However, it seems JavaScript is either disabled or not supported by your browser. To view Google Maps, enable JavaScript by changing your browser options, and then try again.
	</div>
</noscript>

<div class="owa_reportSectionContent">
	<P><img align="bottom" src="<?php echo $this->makeImageLink('kml_feed_small.png');?>"> <a href="<?php echo $this->makeLink(array('do' => 'base.kmlVisitsGeolocation'), true, $this->config['action_url']);?>">Download KML</a></P>
	
	<?php include("map_dom.tpl");?>
</div>

<?php echo $this->makePaginationFromResultSet($latest_visits, array('do' => 'base.reportVisitsGeolocation'));?>



