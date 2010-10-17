<script type="text/x-jqote-template" id="metricInfobox">
 <![CDATA[
 
	<div class="owa_metricInfobox" style="min-width:135px;width:<*= this.width || 'auto' *>;">
	<p class="owa_metricInfoboxLabel"><*= this.label *></p>
	<p class="owa_metricInfoboxLargeNumber"><*= this.formatted_value *></p>
	<p id='<*= this.dom_id *>-sparkline'></p>
	</div>

]]>
</script>

<script type="text/x-jqote-template" id="table-column">
<![CDATA[

<TD class="<*= this.result_type *>cell"><*= this.value *></TD>
		
]]> 
</script>

<script type="text/x-jqote-template" id="table-row">
<![CDATA[
<TR>
<*= this.columns*>
</TR>		
]]> 
</script>

<script type="text/x-jqote-template" id="simpleTable-outer">
<![CDATA[

<table id="<*= this.dom_id *>" class="simpleTable">
	<tr>
		<*= this.headers *>
	</tr>
</table>
]]>
</script>

<script type="text/x-jqote-template" id="simpleTable-headers">
<![CDATA[
<th class="<*= this.result_type *>"><*= this.label *></th>
]]>
</script>