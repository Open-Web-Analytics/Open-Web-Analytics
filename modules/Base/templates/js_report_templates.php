<?php
/*
 * The one jqote template that is still rendered from markup.
 *
 * Five others lived here and were all dead: `metricInfobox` (kpiBox builds the
 * same markup inline with sprintf and never read the template) and the four
 * `simpleTable`/`table-*` ones, whose only consumer was
 * resultSetExplorer.renderResultsRows -- a method nothing called.
 *
 * They were reached by an undeclared DOM-id lookup, which is why nobody
 * noticed: a template that is never fetched and a template that is missing
 * look identical from here. JqoteTemplateContract.test.js now pins both
 * directions, so neither can happen again silently.
 *
 * This one goes too, into the named grid formatter that is its only caller,
 * when attribution-history converts. Only report_dimensionalTrend.php -- the
 * view that report renders through -- still includes this file.
 */
?>
<script type="text/x-jqote-template" id="attributionCell">
<![CDATA[
<b>Attribution <*=(j+1) *>:</b><BR>
<* if (this.md) { *> <i>Medium:</i> <*= this.md *> -> <* } *>
<* if (this.sr) { *> <i>Source:</i> <*= this.sr *> -> <* } *>
<* if (this.cn) { *> <i>Campaign:</i> <*= this.cn *> -> <* } *>
<* if (this.ad) { *> <i>Ad:</i> <*= this.ad *> -> <* } *>
<* if (this.at) { *> <i>Ad Type:</i> <*= this.at *> -> <* } *>
<* if (this.st) { *> <i>Search Terms:</i> <*= this.st *><* } *>
<br>
]]>
</script>
