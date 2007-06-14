<style>

#trends {}
#core_metrics {width: px; float:;}
#summary_stats {margin-left: 420px; width:auto;}

</style>

<? include('report_header.tpl');?>

<div valign="top" id="trend_graph"><img src="<?=$this->graphLink(array('view' => 'base.graphFeedRequests', 'period' => 'last_thirty_days', 'site_id' => $data['site_id']));?>" /></div>

<? include('report_feed_core_metrics.tpl');?>

<table class="layout_container" width="100%">
	<TR>
		<TD valign="top">
						<img src="<?=$this->graphLink(array('view' => 'base.graphFeedReaderTypes'), true);?>">
		</TD>
		<TD valign="top">
			<img src="<?=$this->graphLink(array('view' => 'base.graphFeedFormats'), true);?>">
		</TD>
	</TR>
</table>
	


						