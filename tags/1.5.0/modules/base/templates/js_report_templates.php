<script type="text/x-jqote-template" id="metricInfobox">
 <![CDATA[
 
	<div id = "<*= this.dom_id *>" class="owa_metricInfobox" style="min-width:135px;width:<*= this.width || 'auto' *>;">
	<p class="owa_metricInfoboxLabel"><*= this.label *></p>
	<p class="owa_metricInfoboxLargeNumber"><*= this.formatted_value *></p>
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

<script type="text/x-jqote-template" id="attributionCell">
<![CDATA[
<b>Atribution <*=(j+1) *>:</b><BR>
<* if (this.md) { *> <i>Medium:</i> <*= this.md *> -> <* } *>
<* if (this.sr) { *> <i>Source:</i> <*= this.sr *> -> <* } *>
<* if (this.cn) { *> <i>Campaign:</i> <*= this.cn *> -> <* } *>
<* if (this.ad) { *> <i>Ad:</i> <*= this.ad *> -> <* } *>
<* if (this.at) { *> <i>Ad Type:</i> <*= this.at *> -> <* } *>
<* if (this.st) { *> <i>Search Terms:</i> <*= this.st *><* } *>
<br>
]]>
</script>

