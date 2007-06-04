<H2><?=$headline;?>: <?=$date_label;?></H2>

<table>
	<? if (!empty($visitors)):?>
	<? foreach ($visitors as $visitor):?>
	<TR>
		<TD><img src="<?=$this->makeImageLink('user_icon_small.gif');?>" align="top"> 
			<a href="<?=$this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $visitor['visitor_id']));?>">
			<?if(!empty($visitor['user_name'])): 
				echo $visitor['user_name'];
			elseif(!empty($visitor['user_email'])):
			    echo $visitor['user_email'];
			else:
			    echo $visitor['visitor_id'];
			endif;?>
			</a>
		</TD>
	</TR>
	<? endforeach;?>
	<? else:?>
	<TR>
		<TD>
			There are no visitors during this time period.
		</TD>
	</TR>
	<? endif;?>
</table>