<style>

#trends {}
#core_metrics {width: px; float:;}
#summary_stats {margin-left: 420px; width:auto;}

</style>

<h2><?=$headline;?>: <?=$period_label;?><?=$date_label;?></h2>

<table width="100%">
	<TR>
		<TD valign="top">
			<fieldset id="trends" class="graph">
				<legend>30 Day Trend</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphFeedRequests', 'period' => 'last_thirty_days', 'site_id' => $data['site_id']));?>" />
			</fieldset>
			
			<fieldset id="core_metrics">
				<legend>Core Metrics</legend>
				<? include('report_feed_core_metrics.tpl');?>
			</fieldset>		
		</TD>
		
		<TD valign="top">
			<fieldset id="" class="graph">
				<legend>Feed Readers</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphFeedReaderTypes'), true);?>">
			</fieldset>
			
			<fieldset id="" class="graph">
				<legend>Feed Formats</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphFeedFormats'), true);?>">
			</fieldset>
			
		</TD>
	</TR>
</table>
						