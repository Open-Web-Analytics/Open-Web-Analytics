<div class="wrap">
<fieldset class="options">
	<legend>News</legend>
	<? include('news.tpl');?>
</fieldset>

<BR>

<Table id="layout_panels" cellpadding="0" cellspacing="0">
	<TR>
		<TD colspan="2" class="headline">
			<?=$headline;?>
		</TD>
	</TR>
	<TR>
		<TD colspan="2">
			<P>Open Web Analytics has several configuraion options that can be set using the controls below. Once changes are made click the save button to save the configuration to the database. To learn more about configuring OWA, visit the <a href="http://wiki.openwebanalytics.com">OWA Wiki</a></P>		
		</TD>
	</TR>
	<TR>
		<TD valign="top" id="nav_left">
		
			<? foreach ($panels as $group => $items):?>
			
				<H4><?=$group;?></H4>
					<UL>
					<? foreach ($items as $k => $v):?>
						<? if ($v['view']):?>
						<LI><a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => $v['view']));?>"><?=$v['anchortext'];?></a></LI>
						<? else: ?>
						<LI><a href="<?=$this->makeLink(array('do' => $v['do']));?>"><?=$v['anchortext'];?></a></LI>
						<? endif; ?>
					<? endforeach;?>
					</UL>
			<? endforeach;?>
		</TD>
		<TD class="layout_subview"><?=$subview;?></TD>
	</TR>

</Table>
</div>