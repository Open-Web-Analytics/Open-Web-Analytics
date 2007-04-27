<h2><?=$headline;?> for <?=$period_label;?><?=$date_label;?></h2>

<div id="sub_nav">
	<?=$this->makeNavigation($nav);?>	  
</div>

<div class="post-nav"></div>

	<table width="100%">
		<TR>
			<TD valign="top">
				<fieldset>
					<legend>Document Details</legend>
					<? include('report_document_detail.tpl');?>
				</fieldset>
				<fieldset id="top_referers">
					<legend>Top Referers</legend>
					<? include('report_top_referers.tpl');?>
				</fieldset>
				
			</TD>
			<TD valign="top">
				<fieldset id="document">
					<legend>Summary Stats</legend>
					<? include('report_document_summary_stats.tpl');?>
				</fieldset>
				
				<fieldset>
					<legend>Core Metrics</legend>
					<? include('report_document_core_metrics.tpl');?>
				</fieldset>
			</TD>
		</TR>
	</table>
	