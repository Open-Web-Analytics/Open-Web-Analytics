<h2><?=$headline;?></h2>

<fieldset id="trends" class="options">
	<legend>Trends - <?=$period_label;?></legend>
	<img src="<?=$this->config['action_url']?>?owa_action=graph&name=feed_fetches&type=bar_line&period=last_thirty_days&site_id=<?=$params['site_id'];?>" />
	<BR>
	<? include('feed_core_metrics.tpl');?>
</fieldset>


</fieldset>


<fieldset id="summary_stats" class="options">
	<legend>Feed Details - <?=$period_label;?></legend>

	<TABLE>
		<TR>
			
			<TH>Fead Readers</TH>
			<TH>Feed Fetches By Format</TH>
		</TR>
		<TR>		
			<TD>
				<img src="<?=$this->config['action_url']?>?owa_action=graph&name=feed_reader_uas&type=pie&period=<?=$params['period'];?>&site_id=<?=$params['site_id'];?>" />
			</TD>
			<TD>
				<img src="<?=$this->config['action_url']?>?owa_action=graph&name=feed_formats&type=pie&period=<?=$params['period'];?>&site_id=<?=$params['site_id'];?>" />
			</TD>
		</TR>
		
	</TABLE>
	

</fieldset>


						