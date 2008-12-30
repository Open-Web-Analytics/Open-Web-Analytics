<div class="owa_reportSectionHeader">
	There were <?=$feed_counts['fetch_count'];?> requests of this web site's feeds.
</div>

<div class="owa_reportSectionContent">
	<?=$this->displayChart('feed_trend', $feed_chart_data);?>
	<? include('report_feed_core_metrics.tpl');?>
</div>

<table class="layout_container" width="100%">
	<TR>
		<TD valign="top">
			<?=$this->getWidget('base.widgetFeedTypes');?>
		</TD>
		<TD valign="top">
			<?=$this->getWidget('base.widgetFeedReaderTypes');?>
		</TD>
	</TR>
</table>
	


						