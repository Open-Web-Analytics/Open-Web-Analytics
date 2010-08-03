<script type="text/x-jqote-template" id="metricInfobox">
 <![CDATA[
 
	<div class="owa_metricInfobox" style="min-width:135px;width:<%= this.width %>;">
	<p class="owa_metricInfoboxLabel"><%= this.label %></p>
	<p class="owa_metricInfoboxLargeNumber"><%= this.formatted_value %></p>
	<p id='<%= this.dom_id %>-sparkline'></p>
	</div>

]]>
</script>